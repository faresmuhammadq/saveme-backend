<?php

namespace App\Modules\Billing\Application\Subscriptions;

use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use DateTimeImmutable;

/**
 * The subscription rows of purged accounts are kept 400 days after the
 * purge — identifiers and dates only, the dispute window — then deleted
 * (spec 022, FR-047). Idempotent.
 */
final class PurgeEndedSubscriptions
{
    public function __construct(private readonly SubscriptionRepository $subscriptions) {}

    public function run(?DateTimeImmutable $now = null): int
    {
        $now ??= now()->toDateTimeImmutable();

        return $this->subscriptions->deleteEndedByAccountPurgeBefore($now->modify('-'.(int) config('identity.husk_days').' days'));
    }
}
