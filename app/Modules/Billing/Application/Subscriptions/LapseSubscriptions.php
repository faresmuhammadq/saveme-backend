<?php

namespace App\Modules\Billing\Application\Subscriptions;

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\GoverningSubscription;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\EntitlementChangedDetail;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves subscriptions along their dates (spec 009, US5; ADR-0013 rule 4):
 * past the billing period into grace, past grace into expired. Every move is
 * conditional on the current status, so running twice changes nothing twice.
 * Resolution already follows the dates (rule 14); this keeps the stored
 * status, the events and the audit trail in step with them.
 */
final class LapseSubscriptions
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{graced: int, expired: int}
     */
    public function run(): array
    {
        $now = now()->toDateTimeImmutable();
        $graceDays = (int) config('billing.grace_days');
        $graced = 0;
        $expired = 0;

        foreach ($this->subscriptions->dueForGrace($now) as $subscription) {
            $graceUntil = $subscription->graceUntil ?? $subscription->currentPeriodEnd?->modify("+{$graceDays} days");

            DB::transaction(function () use ($subscription, $graceUntil, &$graced): void {
                if ($this->subscriptions->transition($subscription->id, SubscriptionStatus::Active, SubscriptionStatus::Grace, ['grace_until' => $graceUntil])) {
                    $graced++;
                    event(new SubscriptionLapsed((string) Str::uuid7(), self::stamp(), $subscription->userId, $subscription->id, SubscriptionStatus::Grace->value));
                }
            });
        }

        foreach ($this->subscriptions->dueForExpiry($now) as $subscription) {
            DB::transaction(function () use ($subscription, $now, &$expired): void {
                if (! $this->subscriptions->transition($subscription->id, SubscriptionStatus::Grace, SubscriptionStatus::Expired)) {
                    return;
                }

                $expired++;
                event(new SubscriptionLapsed((string) Str::uuid7(), self::stamp(), $subscription->userId, $subscription->id, SubscriptionStatus::Expired->value));

                // The person's plan changed only if nothing else now governs.
                if (GoverningSubscription::among($this->subscriptions->forUser($subscription->userId), $now) === null) {
                    $this->audit->record(new AuditRecord(
                        AuditEvent::EntitlementChanged, ActorType::System, null, SubjectType::Subscription, $subscription->id,
                        new EntitlementChangedDetail('expire', $subscription->planId),
                    ));
                }
            });
        }

        return ['graced' => $graced, 'expired' => $expired];
    }

    private static function stamp(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
