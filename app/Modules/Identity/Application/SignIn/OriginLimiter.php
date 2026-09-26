<?php

namespace App\Modules\Identity\Application\SignIn;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Failures from one origin across any accounts (security baseline, Lockout):
 * the limit that stops credential stuffing, which spreads too thinly to trip
 * any per-account counter.
 */
final class OriginLimiter
{
    public function secondsBlocked(string $origin): ?int
    {
        $key = $this->key($origin);

        return RateLimiter::tooManyAttempts($key, (int) config('identity.lockout.origin.failures'))
            ? max(1, RateLimiter::availableIn($key))
            : null;
    }

    public function hit(string $origin): void
    {
        RateLimiter::hit($this->key($origin), 60 * (int) config('identity.lockout.origin.decay_minutes'));
    }

    private function key(string $origin): string
    {
        return 'identity-origin:'.$origin;
    }
}
