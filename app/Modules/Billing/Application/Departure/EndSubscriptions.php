<?php

namespace App\Modules\Billing\Application\Departure;

use App\Modules\Billing\Application\Subscriptions\SubscriptionService;
use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;

/**
 * Billing's step of an account purge (spec 022, FR-047): granting `manual`
 * and `instapay` subscriptions end, `google_play` ones are left to the
 * provider, every row is stamped. A no-op on a second call.
 */
final class EndSubscriptions implements AccountPurgeParticipant
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function step(): PurgeStep
    {
        return PurgeStep::Subscriptions;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        return PurgeCounts::of('subscriptions_ended', $this->subscriptions->endForAccountPurge($userId, $context->now));
    }
}
