<?php

namespace App\Modules\Billing\Application\RedeemCodes;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Subscriptions\MySubscription;
use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCodeStatus;
use App\Modules\Billing\Domain\RedeemCodes\RedeemedPeriod;
use App\Modules\Billing\Domain\Refusals\InvalidRedeemCode;
use App\Modules\Billing\Domain\Refusals\RedeemCodeEmailMismatch;
use App\Modules\Billing\Domain\Refusals\RedeemCodeExpired;
use App\Modules\Billing\Domain\Refusals\RedeemCodeUnknown;
use App\Modules\Billing\Domain\Refusals\RedeemCodeUsed;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\EntitlementChangedDetail;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use App\Modules\Identity\Application\VerificationGate;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The in-app "Enter code" (spec 020, US3, P-10 rule 2): turns a Redeem Code
 * into an `instapay` subscription on the signed-in, verified account whose
 * address the code was sent to.
 *
 * Refusals come in the order of FR-026: unknown or revoked, then the same
 * account again (a repeat answer, no new effect), used by another, expired,
 * wrong address, unverified address. The verification check comes last on
 * purpose, so an unverified person with the right address learns only what
 * verifying fixes. Lock order: code, claim, the person's subscriptions.
 */
final class RedeemCodes
{
    public function __construct(
        private readonly RedeemCodeRepository $codes,
        private readonly ClaimRepository $claims,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
        private readonly ClaimAddresses $addresses,
        private readonly VerificationGate $verification,
        private readonly AuditLog $audit,
        private readonly MySubscription $mySubscription,
    ) {}

    public function redeem(string $userId, string $rawCode, string $locale): RedeemResult
    {
        $code = RedeemCode::tryParse($rawCode) ?? throw new InvalidRedeemCode;

        $subscription = DB::transaction(function () use ($userId, $code): SubscriptionRecord {
            $stored = $this->codes->lockByHash($code->hash());

            if ($stored === null || $stored->status === RedeemCodeStatus::Revoked) {
                throw new RedeemCodeUnknown;
            }

            if ($stored->status === RedeemCodeStatus::Redeemed) {
                if ($stored->redeemedBy !== $userId) {
                    throw new RedeemCodeUsed;
                }

                return $this->subscriptions->find((string) $stored->subscriptionId)
                    ?? throw new RuntimeException('A redeemed code points at a missing subscription.');
            }

            $now = now()->toDateTimeImmutable();

            if ($stored->status === RedeemCodeStatus::Expired || $stored->expiresAt <= $now) {
                throw new RedeemCodeExpired;
            }

            $claim = $this->claims->lockForUpdate($stored->claimId)
                ?? throw new RuntimeException('A code points at a missing claim.');

            if ($claim->addressLookup === null || ! hash_equals($claim->addressLookup, $this->addresses->lookupOfUser($userId))) {
                throw new RedeemCodeEmailMismatch;
            }

            $this->verification->assertVerified($userId);

            $plan = $this->plans->find($claim->planId) ?? throw new RuntimeException('A claim points at a missing plan.');
            $held = $this->subscriptions->lockForUser($userId);
            $period = RedeemedPeriod::for($plan->periodType, $plan->periodDays, $now, $held);

            $subscription = $this->subscriptions->create([
                'user_id' => $userId,
                'plan_id' => $plan->id,
                'provider' => 'instapay',
                'provider_ref' => $claim->id,
                'status' => SubscriptionStatus::Active->value,
                'current_period_start' => $period->start,
                'current_period_end' => $period->end,
                'grace_until' => null,
                'auto_renew' => false,
            ]);

            $this->codes->markRedeemed($stored->id, $userId, $subscription->id, $now);
            $this->claims->close($claim->id, $now);

            $this->audit->record(AuditRecord::byUser(
                AuditEvent::EntitlementChanged,
                $userId,
                SubjectType::Subscription,
                $subscription->id,
                new EntitlementChangedDetail('redeem', $plan->id),
            ));
            event(new SubscriptionActivated((string) Str::uuid7(), now()->utc()->format('Y-m-d\TH:i:s.u\Z'), $userId, $subscription->id, $plan->id));

            return $subscription;
        });

        $plan = $this->plans->find($subscription->planId) ?? throw new RuntimeException('A subscription points at a missing plan.');
        // A fresh resolver: nothing resolved earlier in this request may colour the answer.
        $entitlements = new EntitlementService($this->subscriptions, $this->plans);

        return new RedeemResult(
            subscriptionId: $subscription->id,
            planId: $plan->id,
            planName: $plan->nameIn($locale),
            status: $subscription->status->value,
            currentPeriodStart: $subscription->currentPeriodStart,
            currentPeriodEnd: $subscription->currentPeriodEnd,
            governing: $this->mySubscription->toWire($userId, $locale),
            entitlements: $entitlements->summaryFor($userId)->toWire($locale),
        );
    }
}
