<?php

namespace App\Modules\Billing\Application\Entitlements;

use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\GoverningSubscription;
use App\Modules\Wallets\Application\MembershipQueryService;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * The only path to a capability decision (P-09 rule 2; ADR-0013 rules 5–8,
 * 13–14). A person is answered by their governing subscription's plan, or by
 * the default plan when none governs. Bound scoped, and the memo is keyed on
 * the request id as well: an answer never outlives one request or job, even
 * where the container outlives the request.
 *
 * forWallet() answers a wallet by its owner's entitlements (rule 7).
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

    /**
     * A shared wallet is governed by its owner's entitlements, not the
     * caller's (P-09 rule 4; ADR-0013 rule 7). The owner is looked up on every
     * call, so an ownership transfer is followed on the next request. Wallets
     * is resolved lazily: Wallets asks this service too, and resolving it in
     * the constructor would make the two depend on each other at build time.
     *
     * @throws RuntimeException when the wallet has no live owner (unknown or deleted)
     */
    public function forWallet(string $walletId): Entitlements
    {
        $ownerId = app(MembershipQueryService::class)->ownerOf($walletId)
            ?? throw new RuntimeException('A wallet with no live owner has no entitlements.');

        return $this->forUser($ownerId);
    }

    public function summaryFor(string $userId): EntitlementSummary
    {
        return $this->forUser($userId)->summary();
    }
}
