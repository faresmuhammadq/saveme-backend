<?php

namespace App\Modules\Billing\Domain\Subscriptions;

/**
 * ADR-0013 rule 4: active → grace → expired; active|grace → revoked
 * (terminal); expired|grace → active on renewal, recovered payment, claim
 * or extension.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function canBecome(self $next): bool
    {
        return match ($this) {
            self::Active => in_array($next, [self::Grace, self::Revoked], true),
            self::Grace => in_array($next, [self::Active, self::Expired, self::Revoked], true),
            self::Expired => $next === self::Active,
            self::Revoked => false,
        };
    }

    public function grants(): bool
    {
        return $this === self::Active || $this === self::Grace;
    }
}
