<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * Which Google Play product family a purchase belongs to: an auto-renewing
 * subscription, or a one-time (lifetime) product (spec 024, FR-028).
 */
enum PlayProductKind: string
{
    case Subscription = 'subscription';
    case OneTime = 'one_time';
}
