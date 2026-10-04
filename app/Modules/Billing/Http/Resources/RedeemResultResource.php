<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Application\RedeemCodes\RedeemResult;

/**
 * The redeem answer on the wire (spec 020, FR-031). `governing` and
 * `entitlements` already carry the shapes of `/me/subscription` and
 * `/me/entitlements`.
 */
final class RedeemResultResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(RedeemResult $result): array
    {
        return $result->toWire();
    }
}
