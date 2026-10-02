<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Problems\ApiRefusal;

/**
 * The category identifier in a path: a client-generated UUIDv7 (P-06), the
 * same shape as a wallet's.
 */
final class CategoryId
{
    /**
     * @throws ApiRefusal INVALID_ID
     */
    public static function assert(mixed $id): string
    {
        return WalletId::assert($id);
    }
}
