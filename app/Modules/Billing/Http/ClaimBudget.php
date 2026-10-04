<?php

namespace App\Modules\Billing\Http;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The claim form's two budgets (security baseline, Route budgets; spec 020,
 * FR-010, R-12): 5 per hour per origin and 5 per 24 hours per address lookup
 * value, registered or not. Spent in the controller after the body validated,
 * so a typo costs nothing and a replay (answered by the idempotency
 * middleware) never reaches it. Both budgets are checked before either is
 * spent.
 */
final class ClaimBudget
{
    private const HOUR = 3600;

    private const DAY = 86400;

    /**
     * @throws ThrottleRequestsException when either budget is spent
     */
    public static function spend(?string $origin, string $addressLookup): void
    {
        $perOrigin = (int) config('billing.instapay.throttle.claims_per_hour_per_origin');
        $perAddress = (int) config('billing.instapay.throttle.claims_per_day_per_address');
        $originKey = 'billing-claim-origin:'.($origin ?? 'unknown');
        $addressKey = 'billing-claim-address:'.$addressLookup;

        foreach ([[$originKey, $perOrigin], [$addressKey, $perAddress]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => RateLimiter::availableIn($key),
                    'X-RateLimit-Limit' => $max,
                ]);
            }
        }

        RateLimiter::hit($originKey, self::HOUR);
        RateLimiter::hit($addressKey, self::DAY);
    }
}
