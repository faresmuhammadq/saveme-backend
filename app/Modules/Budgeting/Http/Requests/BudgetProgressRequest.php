<?php

namespace App\Modules\Budgeting\Http\Requests;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\Request;

/**
 * The `date` query parameter of both progress reads (spec 015, FR-016): a
 * real calendar date, 1900-01-01 to 2999-12-31, else 400
 * INVALID_QUERY_PARAMETER. Absent means today in the owner's timezone.
 */
final class BudgetProgressRequest
{
    /**
     * @return ?string YYYY-MM-DD
     *
     * @throws ApiRefusal INVALID_QUERY_PARAMETER
     */
    public static function date(Request $request): ?string
    {
        $date = $request->query('date');

        if ($date === null) {
            return null;
        }

        if (! is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $date < '1900-01-01' || $date > '2999-12-31') {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        return $date;
    }
}
