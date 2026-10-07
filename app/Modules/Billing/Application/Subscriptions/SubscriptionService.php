<?php

namespace App\Modules\Billing\Application\Subscriptions;

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\EntitlementChangedDetail;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Subscription changes the system makes (spec 022, FR-047, A-19).
 */
final class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly AuditLog $audit,
        private readonly PlayPurchaseRepository $playPurchases,
        private readonly PlayAccountReferences $playReferences,
    ) {}

    /**
     * Ends the access an account's subscriptions grant because the account
     * is being purged. Every granting `manual` or `instapay` subscription is
     * ended as an operator's revocation ends it — `revoked`, one
     * `entitlement_changed` entry each (actor `system`, reason
     * `account_deleted`) and the lapse event. A `google_play` subscription is
     * never changed: it cannot be cancelled from here and lapses through the
     * provider's own flow. Every row of the account is stamped, to be deleted
     * 400 days later. The account's Play Account Reference is deleted and the
     * sealed purchase tokens of its Play purchases are erased (spec 024,
     * FR-048); the purchases and their `google_play` subscriptions stay,
     * stamped, for the same 400 days. Running it again changes nothing.
     *
     * @return int subscriptions ended by this call
     */
    public function endForAccountPurge(string $userId, DateTimeImmutable $at): int
    {
        return DB::transaction(function () use ($userId, $at): int {
            $ended = 0;

            foreach ($this->subscriptions->lockForUser($userId) as $subscription) {
                if ($subscription->provider === 'google_play' || ! $subscription->status->grants()) {
                    continue;
                }

                if (! $this->subscriptions->transition($subscription->id, $subscription->status, SubscriptionStatus::Revoked)) {
                    continue;
                }

                $this->audit->record(new AuditRecord(
                    AuditEvent::EntitlementChanged,
                    ActorType::System,
                    null,
                    SubjectType::Subscription,
                    $subscription->id,
                    new EntitlementChangedDetail('revoke', $subscription->planId, null, 'account_deleted'),
                ));
                event(new SubscriptionLapsed((string) Str::uuid7(), now()->utc()->format('Y-m-d\TH:i:s.u\Z'), $userId, $subscription->id, SubscriptionStatus::Revoked->value));
                $ended++;
            }

            $this->subscriptions->stampEndedByAccountPurge($userId, $at);
            $this->playPurchases->eraseForAccountPurge($userId, $at);
            $this->playReferences->delete($userId);

            return $ended;
        });
    }
}
