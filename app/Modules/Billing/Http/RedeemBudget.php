<?php

namespace App\Modules\Billing\Http;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The redeem budget (security baseline, Route budgets; spec 020, FR-033):
 * 10 attempts per hour per person. Spent in the controller after the code's
 * format validated, so a typo costs nothing and a replay (answered by the
 * idempotency middleware) never reaches it.
 */
final class RedeemBudget
{
    private const HOUR = 3600;

    /**
     * @throws ThrottleRequestsException when the budget is spent
     */
    public static function spend(string $userId): void
    {
        $key = 'billing-redeem:'.$userId;
        $max = (int) config('billing.instapay.throttle.redeem_per_hour');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => RateLimiter::availableIn($key),
                'X-RateLimit-Limit' => $max,
            ]);
        }

        RateLimiter::hit($key, self::HOUR);
    }
}
