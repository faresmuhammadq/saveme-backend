<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * Google's last reported state of a purchase, as stored (data-model.md).
 * `pending_canceled` never reaches the wire: a purchase canceled before it
 * was ever paid is `PURCHASE_NOT_VERIFIED`.
 */
enum PlayState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Grace = 'grace';
    case Canceled = 'canceled';
    case OnHold = 'on_hold';
    case Paused = 'paused';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case PendingCanceled = 'pending_canceled';

    /**
     * Whether Google reports the purchase as paid and still entitling: the
     * states in which the server acknowledges it.
     */
    public function isPaid(): bool
    {
        return in_array($this, [self::Active, self::Grace, self::Canceled], true);
    }
}
