<?php

namespace App\Modules\Billing\Application\Subscriptions;

use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use DateTimeImmutable;

/**
 * The subscription rows of purged accounts are kept 400 days after the
 * purge — identifiers and dates only, the dispute window — then deleted
 * (spec 022, FR-047). Idempotent.
 */
final class PurgeEndedSubscriptions
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlayPurchaseRepository $playPurchases,
    ) {}

    public function run(?DateTimeImmutable $now = null): int
    {
        $now ??= now()->toDateTimeImmutable();

        $cut = $now->modify('-'.(int) config('identity.husk_days').' days');

        // A Play purchase points at its subscription, so it goes first (spec 024, FR-048).
        $this->playPurchases->deleteEndedByAccountPurgeBefore($cut);

        return $this->subscriptions->deleteEndedByAccountPurgeBefore($cut);
    }
}
