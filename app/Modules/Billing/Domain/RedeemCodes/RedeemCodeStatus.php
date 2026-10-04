<?php

namespace App\Modules\Billing\Domain\RedeemCodes;

/**
 * A Redeem Code's states (glossary; spec 020): `issued` becomes `redeemed`,
 * `revoked` or `expired`, and never changes again.
 */
enum RedeemCodeStatus: string
{
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
