<?php

namespace App\Modules\Notifications\Domain\Delivery;

/**
 * Why a notification was recorded as skipped (spec 023, FR-035).
 */
enum SkipReason: string
{
    case CategoryOff = 'category_off';
    case NoDevice = 'no_device';
    case RateLimited = 'rate_limited';
    case Collapsed = 'collapsed';
    case InactiveAccount = 'inactive_account';
    case Disabled = 'disabled';
    case Summarised = 'summarised';
}
