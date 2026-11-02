<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\Request;

/**
 * The `from` and `to` query parameters of a Shared Budget report: both
 * required, real calendar dates from 1900-01-01 to 2999-12-31, `to` not before
 * `from`, else 400 INVALID_QUERY_PARAMETER.
 */
final class SharedBudgetRange
{
    /**
     * @return array{string, string} YYYY-MM-DD, YYYY-MM-DD
     *
     * @throws ApiRefusal INVALID_QUERY_PARAMETER
     */
    public static function from(Request $request): array
    {
        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));

        if ($to < $from) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        return [$from, $to];
    }

    private static function date(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $value < '1900-01-01' || $value > '2999-12-31') {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        return $value;
    }
}
