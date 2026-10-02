<?php

namespace App\Modules\Sharing\Http;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;

/**
 * The identifiers in Sharing's paths. A wallet and an invitation are
 * client-generated UUIDv7 (P-06); a member is named by their user id, which
 * the server generated, so any UUID shape is accepted. A malformed one is
 * INVALID_ID, before the body is looked at (spec 016, section 6).
 */
final class PathIds
{
    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * @throws ApiRefusal INVALID_ID
     */
    public static function wallet(mixed $id): string
    {
        return self::match($id, self::UUID_V7);
    }

    /**
     * @throws ApiRefusal INVALID_ID
     */
    public static function invitation(mixed $id): string
    {
        return self::match($id, self::UUID_V7);
    }

    /**
     * @throws ApiRefusal INVALID_ID
     */
    public static function user(mixed $id): string
    {
        return self::match($id, self::UUID);
    }

    private static function match(mixed $id, string $pattern): string
    {
        if (! is_string($id) || preg_match($pattern, $id) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidId);
        }

        return $id;
    }
}
