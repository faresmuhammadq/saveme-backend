<?php

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A subscription stopped granting fully: into grace, expired, or revoked.
 * Dispatched after commit, identifiers and scalars only (P-11).
 */
final readonly class SubscriptionLapsed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $subscriptionId,
        public string $status,
    ) {}
}
