<?php

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A subscription started granting: granted, extended back to life, renewed or approved.
 * Dispatched after commit, identifiers and scalars only (P-11).
 */
final readonly class SubscriptionActivated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $subscriptionId,
        public string $planId,
    ) {}
}
