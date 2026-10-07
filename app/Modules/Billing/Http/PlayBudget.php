<?php

namespace App\Modules\Billing\Http;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The Google Play budgets (security baseline, Route budgets; spec 024,
 * FR-054): verify 20 per hour and restore 10 per hour, per person. Spent in
 * the controller after validation, so a malformed request costs nothing and
 * a replay (answered by the idempotency middleware) never reaches it.
 */
final class PlayBudget
{
    private const HOUR = 3600;

    public static function spendVerify(string $userId): void
    {
        self::spend('billing-play-verify:'.$userId, (int) config('billing.google_play.throttle.verify_per_hour'));
    }

    public static function spendRestore(string $userId): void
    {
        self::spend('billing-play-restore:'.$userId, (int) config('billing.google_play.throttle.restore_per_hour'));
    }

    /**
     * @throws ThrottleRequestsException when the budget is spent
     */
    private static function spend(string $key, int $max): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => RateLimiter::availableIn($key),
                'X-RateLimit-Limit' => $max,
            ]);
        }

        RateLimiter::hit($key, self::HOUR);
    }
}
