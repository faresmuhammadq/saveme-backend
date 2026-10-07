<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\GooglePlay\PlayPurchaseRecord;
use App\Modules\Billing\Domain\GooglePlay\PlayStateMap;
use App\Modules\Billing\Domain\GooglePlay\PlayTransition;
use App\Modules\Billing\Domain\GooglePlay\StoredPlayPurchase;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\EntitlementChangedDetail;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Writes what one Google read comes to onto the subscription (spec 024,
 * R-5, FR-020 to FR-029, FR-051): create it for a first grant or move it
 * along `PlayStateMap`'s drawn arcs, through `SubscriptionRepository`
 * only, with one `entitlement_changed` entry and one event per real change.
 * A repeat of the same state writes and publishes nothing. Runs inside the
 * caller's transaction with the purchase row locked; the events are
 * dispatched after commit (P-11).
 */
final class ApplyPlayState
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlayPurchaseRepository $purchases,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return ?string the subscription's id, if the purchase backs one
     */
    public function apply(StoredPlayPurchase $purchase, PlayPurchaseRecord $record, PlanRecord $plan, string $userId, DateTimeImmutable $now, string $revokeReason = 'revoked_by_provider'): ?string
    {
        $current = $this->current($purchase);
        $transition = PlayStateMap::target($record, $current, $now, $revokeReason);

        if ($transition->ignored) {
            PlayLog::stateIgnored($purchase->id);
        }

        return $this->execute($transition, $purchase, $current, $plan->id, $userId, $now, $record);
    }

    /**
     * Revokes the subscription of a voided purchase.
     */
    public function revoke(StoredPlayPurchase $purchase, string $userId, string $reason, DateTimeImmutable $now): void
    {
        $current = $this->current($purchase);

        if ($current === null) {
            return;
        }

        $this->execute(PlayStateMap::revoking($current, $reason), $purchase, $current, $current->planId, $userId, $now, null);
    }

    /**
     * Ends a granting subscription as expired: its purchase was replaced by
     * another (FR-010), or Google stayed unreachable past the fallback (FR-046).
     */
    public function expire(StoredPlayPurchase $purchase, string $userId, DateTimeImmutable $now): void
    {
        $current = $this->current($purchase);

        if ($current === null) {
            return;
        }

        $this->execute(PlayStateMap::expiring($current, $now), $purchase, $current, $current->planId, $userId, $now, null);
    }

    private function current(StoredPlayPurchase $purchase): ?SubscriptionRecord
    {
        return $purchase->subscriptionId === null ? null : $this->subscriptions->find($purchase->subscriptionId);
    }

    private function execute(PlayTransition $transition, StoredPlayPurchase $purchase, ?SubscriptionRecord $current, string $planId, string $userId, DateTimeImmutable $now, ?PlayPurchaseRecord $record): ?string
    {
        if (! $transition->changed || $transition->status === null) {
            return $current?->id;
        }

        $status = $transition->status;

        if ($transition->create) {
            $created = $this->subscriptions->create([
                'user_id' => $userId,
                'plan_id' => $planId,
                'provider' => 'google_play',
                'provider_ref' => $purchase->id,
                'status' => $status->value,
                'current_period_start' => $transition->periodStart ?? $now,
                'current_period_end' => $transition->periodEnd,
                'grace_until' => $transition->graceUntil,
                'auto_renew' => $transition->autoRenew,
                'source_meta' => ['test' => $record !== null ? $record->isTest : $purchase->isTest, 'base_plan_id' => $record !== null ? $record->basePlanId : $purchase->basePlanId],
            ]);
            $this->purchases->update($purchase->id, ['subscription_id' => $created->id]);
            $subscriptionId = $created->id;
        } else {
            $subscriptionId = (string) $current?->id;
            $dates = [
                'current_period_start' => $transition->periodStart,
                'current_period_end' => $transition->periodEnd,
                'grace_until' => $transition->graceUntil,
            ];

            if ($transition->viaGraceHop) {
                // ADR-0013 rule 4 draws no active → expired arc: through a zero-length grace, in one transaction.
                $this->subscriptions->transition($subscriptionId, SubscriptionStatus::Active, SubscriptionStatus::Grace, ['grace_until' => $now]);
                $this->subscriptions->transition($subscriptionId, SubscriptionStatus::Grace, SubscriptionStatus::Expired);
            } elseif ($current !== null && $current->status !== $status) {
                $this->subscriptions->transition($subscriptionId, $current->status, $status, $status === SubscriptionStatus::Revoked || $status === SubscriptionStatus::Expired ? [] : $dates);
            } else {
                $this->subscriptions->updateDates($subscriptionId, $dates);
            }

            if ($current !== null && $current->autoRenew !== $transition->autoRenew && $status->grants()) {
                $this->subscriptions->updateAutoRenew($subscriptionId, $transition->autoRenew);
            }
        }

        if ($transition->auditAction !== null) {
            $this->audit->record(new AuditRecord(
                AuditEvent::EntitlementChanged,
                ActorType::System,
                null,
                SubjectType::Subscription,
                $subscriptionId,
                new EntitlementChangedDetail($transition->auditAction, $planId, null, $transition->auditReason),
            ));
        }

        $stamp = now()->utc()->format('Y-m-d\TH:i:s.u\Z');

        if ($transition->event === PlayTransition::EVENT_ACTIVATED) {
            event(new SubscriptionActivated((string) Str::uuid7(), $stamp, $userId, $subscriptionId, $planId));
        } elseif ($transition->event === PlayTransition::EVENT_LAPSED) {
            event(new SubscriptionLapsed((string) Str::uuid7(), $stamp, $userId, $subscriptionId, $status->value));
        }

        return $subscriptionId;
    }
}
