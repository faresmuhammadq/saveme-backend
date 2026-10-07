<?php

namespace App\Modules\Billing\Http\Resources;

/**
 * The verify answer on the wire (spec 024, FR-011): `purchase`, `entitlements`
 * and `subscription`. Never the token, the order id or the reference.
 */
final class PlayPurchaseResultResource
{
    /**
     * @param  array{purchase: array<string, mixed>, entitlements: array<string, mixed>, subscription: array<string, mixed>, pending: bool}  $result
     * @return array{purchase: array<string, mixed>, entitlements: array<string, mixed>, subscription: array<string, mixed>}
     */
    public static function toArray(array $result): array
    {
        return ['purchase' => $result['purchase'], 'entitlements' => $result['entitlements'], 'subscription' => $result['subscription']];
    }
}
