<?php

namespace App\Modules\Wallets\Http\Requests\Recurring;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;

/**
 * The recurring rule identifier in a path: a client-generated UUIDv7 (P-06),
 * so a rule can be created offline and the write retried safely.
 */
final class RuleId
{
    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * @throws ApiRefusal INVALID_ID
     */
    public static function assert(mixed $id): string
    {
        if (! is_string($id) || preg_match(self::UUID_V7, $id) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidId);
        }

        return $id;
    }
}
