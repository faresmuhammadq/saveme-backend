<?php

namespace App\Modules\Billing\Application\Entitlements;

use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\GoverningSubscription;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * The only path to a capability decision (P-09 rule 2; ADR-0013 rules 5–8,
 * 13–14). A person is answered by their governing subscription's plan, or by
 * the default plan when none governs. Bound scoped, and the memo is keyed on
 * the request id as well: an answer never outlives one request or job, even
 * where the container outlives the request.
 *
 * forWallet() (owner's entitlements, rule 7) arrives with Wallets (B4).
 */
final class EntitlementService
{
    /** @var array<string, Entitlements> */
    private array $resolved = [];

    /** The request the memo belongs to; a new request id empties it. */
    private ?string $memoFor = null;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
    ) {}

    public function forUser(string $userId): Entitlements
    {
        $requestId = Context::get('request_id');
        $requestId = is_string($requestId) ? $requestId : null;

        if ($requestId !== $this->memoFor) {
            $this->resolved = [];
            $this->memoFor = $requestId;
        }

        if (isset($this->resolved[$userId])) {
            return $this->resolved[$userId];
        }

        $governing = GoverningSubscription::among($this->subscriptions->forUser($userId), now()->toDateTimeImmutable());
        $plan = $governing === null
            ? $this->plans->defaultPlan()
            : ($this->plans->find($governing->planId) ?? throw new RuntimeException('A subscription points at a missing plan.'));

        return $this->resolved[$userId] = new Entitlements($plan);
    }

    public function summaryFor(string $userId): EntitlementSummary
    {
        return $this->forUser($userId)->summary();
    }
}
