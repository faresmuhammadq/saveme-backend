<?php

namespace App\Modules\Billing\Application\Subscriptions;

use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Refusals\AccountNotActive;
use App\Modules\Billing\Domain\Refusals\InvalidDays;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\PlanNotGrantable;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotExtendable;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotFound;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotRevocable;
use App\Modules\Billing\Domain\Refusals\SubscriptionRevoked;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\EntitlementChangedDetail;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What an operator may do to a person's access (spec 009, US4; ADR-0013):
 * grant a plan through the `manual` channel, extend a subscription, or
 * revoke one. Each is one unit of work with exactly one `entitlement_changed`
 * audit entry, and carries the operator's reason. The back office (B12)
 * calls these; they have no HTTP surface of their own.
 *
 * The channel and account rules live here, not on the screen (spec 021,
 * FR-029, R-7): only a `manual` subscription with an end can be extended, a
 * `google_play` one is never revoked, and access is only granted or extended
 * for an `active` account.
 */
final class OperatorSubscriptions
{
    /** The most days one grant or extension may add (spec 021, FR-026). */
    public const MAX_DAYS = 3650;

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly SubscriptionRepository $subscriptions,
        private readonly AuditLog $audit,
        private readonly Accounts $accounts,
    ) {}

    /**
     * @param  int|null  $days  null grants for life
     */
    public function grant(string $operatorId, string $userId, string $planId, ?int $days, string $reason): SubscriptionRecord
    {
        $reason = self::reason($reason);
        self::days($days);
        $plan = $this->plans->find($planId);

        if ($plan === null || $plan->isDefault || $plan->status !== 'active') {
            throw new PlanNotGrantable;
        }

        if (! $this->accounts->isActive($userId)) {
            throw new AccountNotActive;
        }

        return DB::transaction(function () use ($operatorId, $userId, $planId, $days, $reason): SubscriptionRecord {
            $now = now()->toDateTimeImmutable();
            $subscription = $this->subscriptions->create([
                'user_id' => $userId,
                'plan_id' => $planId,
                'provider' => 'manual',
                'provider_ref' => 'op:'.$operatorId.':'.Str::uuid7(),
                'status' => SubscriptionStatus::Active->value,
                'current_period_start' => $now,
                'current_period_end' => $days === null ? null : $now->modify("+{$days} days"),
                'grace_until' => null,
                'auto_renew' => false,
            ]);

            $this->record($operatorId, $subscription->id, new EntitlementChangedDetail('grant', $planId, $days, $reason));
            event(new SubscriptionActivated((string) Str::uuid7(), self::stamp(), $userId, $subscription->id, $planId));

            return $subscription;
        });
    }

    public function extend(string $operatorId, string $subscriptionId, int $days, string $reason): SubscriptionRecord
    {
        $reason = self::reason($reason);
        self::days($days);
        $subscription = $this->subscriptions->find($subscriptionId) ?? throw new SubscriptionNotFound;

        if ($subscription->status === SubscriptionStatus::Revoked) {
            throw new SubscriptionRevoked;
        }

        if ($subscription->provider !== 'manual' || $subscription->currentPeriodEnd === null) {
            throw new SubscriptionNotExtendable;
        }

        if (! $this->accounts->isActive($subscription->userId)) {
            throw new AccountNotActive;
        }

        return DB::transaction(function () use ($operatorId, $subscription, $days, $reason): SubscriptionRecord {
            $now = now()->toDateTimeImmutable();
            $base = max($subscription->currentPeriodEnd, $now);
            $end = $base->modify("+{$days} days");
            $changes = ['current_period_end' => $end, 'grace_until' => null];

            $reactivated = $subscription->status !== SubscriptionStatus::Active;
            if ($reactivated) {
                // grace|expired → active (ADR-0013 rule 4); conditional, so a concurrent change wins cleanly.
                if (! $this->subscriptions->transition($subscription->id, $subscription->status, SubscriptionStatus::Active, $changes)) {
                    throw new SubscriptionNotFound;
                }
            } else {
                $this->subscriptions->updateDates($subscription->id, $changes);
            }

            $this->record($operatorId, $subscription->id, new EntitlementChangedDetail('extend', $subscription->planId, $days, $reason));

            if ($reactivated) {
                event(new SubscriptionActivated((string) Str::uuid7(), self::stamp(), $subscription->userId, $subscription->id, $subscription->planId));
            }

            return $this->subscriptions->find($subscription->id) ?? throw new SubscriptionNotFound;
        });
    }

    /**
     * Revoking is final. Revoking an already revoked subscription does nothing.
     */
    public function revoke(string $operatorId, string $subscriptionId, string $reason): void
    {
        $reason = self::reason($reason);
        $subscription = $this->subscriptions->find($subscriptionId) ?? throw new SubscriptionNotFound;

        if ($subscription->provider === 'google_play') {
            throw new SubscriptionNotRevocable;
        }

        if ($subscription->status === SubscriptionStatus::Revoked) {
            return;
        }

        DB::transaction(function () use ($operatorId, $subscription, $reason): void {
            if (! $this->subscriptions->transition($subscription->id, $subscription->status, SubscriptionStatus::Revoked)) {
                return;
            }

            $this->record($operatorId, $subscription->id, new EntitlementChangedDetail('revoke', $subscription->planId, null, $reason));
            event(new SubscriptionLapsed((string) Str::uuid7(), self::stamp(), $subscription->userId, $subscription->id, SubscriptionStatus::Revoked->value));
        });
    }

    private function record(string $operatorId, string $subscriptionId, EntitlementChangedDetail $detail): void
    {
        $this->audit->record(new AuditRecord(AuditEvent::EntitlementChanged, ActorType::Operator, $operatorId, SubjectType::Subscription, $subscriptionId, $detail));
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 1 || $length > 500) {
            throw new InvalidReason;
        }

        return $reason;
    }

    private static function days(?int $days): void
    {
        if ($days !== null && ($days < 1 || $days > self::MAX_DAYS)) {
            throw new InvalidDays;
        }
    }

    private static function stamp(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
