<?php

namespace App\Modules\Billing\Http\Resources;

/**
 * The restore answer on the wire (spec 024, FR-017): one result per item in
 * request order, then `entitlements` and `subscription`.
 */
final class PlayRestoreResultResource
{
    /**
     * @param  array{results: list<array{index: int, product_id: string, outcome: string}>, entitlements: array<string, mixed>, subscription: array<string, mixed>}  $result
     * @return array<string, mixed>
     */
    public static function toArray(array $result): array
    {
        return ['results' => $result['results'], 'entitlements' => $result['entitlements'], 'subscription' => $result['subscription']];
    }
}
