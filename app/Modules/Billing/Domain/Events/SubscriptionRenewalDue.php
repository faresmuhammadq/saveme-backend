<?php

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An InstaPay subscription's billing period ends within the reminder window
 * and the renewal reminder was just stamped (spec 023, FR-027). Published
 * beside the existing mail; Notifications tells the person by push.
 * Dispatched after commit, identifiers and scalars only (P-11).
 */
final readonly class SubscriptionRenewalDue implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $subscriptionId,
    ) {}
}
