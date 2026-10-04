<?php

namespace App\Modules\Reporting\Http;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Domain\ReportRange;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Money\Currency;
use Illuminate\Http\Request;

/**
 * The query string of the six report operations (spec 019, FR-019, research
 * R-8). Anything that would change the answer and is malformed or
 * contradictory is 400 INVALID_QUERY_PARAMETER; an empty value is absent and
 * a parameter the operation does not declare is ignored.
 */
final class ReportQueryParser
{
    /**
     * Summary and by-category: `date` or `from` with `to`, and `include_planned`.
     */
    public const RANGE = 'range';

    /**
     * Trend: `date`, `months` and `include_planned`.
     */
    public const TREND = 'trend';

    /**
     * @param  self::RANGE|self::TREND  $kind
     * @param  bool  $crossWallet  whether `currency` is declared
     *
     * @throws ApiRefusal INVALID_QUERY_PARAMETER
     */
    public static function parse(Request $request, string $kind, bool $crossWallet): ReportRequest
    {
        $date = self::date(self::value($request, 'date'));
        $range = null;

        if ($kind === self::RANGE) {
            $from = self::value($request, 'from');
            $to = self::value($request, 'to');

            if (($from !== null) !== ($to !== null) || ($date !== null && $from !== null)) {
                throw self::refusal();
            }

            if ($from !== null && $to !== null) {
                $range = ReportRange::explicit(self::calendarDate($from), self::calendarDate($to));
            }
        }

        $months = $kind === self::TREND ? self::months(self::value($request, 'months')) : ReportRequest::DEFAULT_MONTHS;
        $planned = self::includePlanned(self::value($request, 'include_planned'));
        $currency = $crossWallet ? self::currency(self::value($request, 'currency')) : null;

        return new ReportRequest($date, $range, $months, $planned, $currency);
    }

    /**
     * The raw string of a parameter; null when absent or empty.
     */
    private static function value(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw self::refusal();
        }

        return $value;
    }

    private static function date(?string $value): ?string
    {
        return $value === null ? null : self::calendarDate($value);
    }

    /**
     * A real calendar date within 1900-01-01 to 2999-12-31.
     */
    private static function calendarDate(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $value < '1900-01-01' || $value > '2999-12-31') {
            throw self::refusal();
        }

        return $value;
    }

    private static function months(?string $value): int
    {
        if ($value === null) {
            return ReportRequest::DEFAULT_MONTHS;
        }

        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1 || strlen($value) > 2 || (int) $value < 1 || (int) $value > 12) {
            throw self::refusal();
        }

        return (int) $value;
    }

    private static function includePlanned(?string $value): bool
    {
        return match ($value) {
            null, 'false' => false,
            'true' => true,
            default => throw self::refusal(),
        };
    }

    /**
     * Uppercase alpha-3 and an active ISO 4217 code: no wallet can hold any other.
     */
    private static function currency(?string $value): ?string
    {
        if ($value !== null && (preg_match('/^[A-Z]{3}$/', $value) !== 1 || ! Currency::isKnown($value))) {
            throw self::refusal();
        }

        return $value;
    }

    private static function refusal(): ApiRefusal
    {
        return new ApiRefusal(ErrorCode::InvalidQueryParameter);
    }
}
