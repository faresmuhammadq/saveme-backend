<?php

namespace App\Modules\Sharing\Http;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The 30-per-hour budget per person shared by preview, accept and decline
 * (security baseline, Route budgets). Spent in the controller, after the
 * body has been validated, so a malformed body is 422 before 429 (spec 016,
 * section 6).
 */
final class TokenBudget
{
    private const WINDOW_SECONDS = 3600;

    /**
     * Counts one call and returns the headers every answer carries.
     *
     * @return array<string, string>
     *
     * @throws ApiRefusal RATE_LIMITED
     */
    public static function spend(string $userId): array
    {
        $key = 'sharing-token:'.$userId;
        $max = (int) config('sharing.throttle.token_per_hour');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiRefusal(ErrorCode::RateLimited, retryAfterSeconds: RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return [
            'RateLimit-Limit' => (string) $max,
            'RateLimit-Remaining' => (string) RateLimiter::remaining($key, $max),
            'RateLimit-Reset' => (string) RateLimiter::availableIn($key),
        ];
    }
}
