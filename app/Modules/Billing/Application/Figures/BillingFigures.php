<?php

namespace App\Modules\Billing\Application\Figures;

use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use DateTimeImmutable;

/**
 * What the back-office overview may ask Billing: counts by channel, computed
 * on each call (spec 021, R-11).
 */
final class BillingFigures
{
    public function __construct(private readonly SubscriptionRepository $subscriptions) {}

    public function compute(DateTimeImmutable $now): BillingFigureSet
    {
        $counts = $this->subscriptions->figures($now);

        return new BillingFigureSet(
            paidGooglePlay: $counts['google_play'],
            testGooglePlay: $counts['google_play_test'],
            paidInstapay: $counts['instapay'],
            grantedManual: $counts['manual'],
            inGrace: $counts['in_grace'],
        );
    }
}
