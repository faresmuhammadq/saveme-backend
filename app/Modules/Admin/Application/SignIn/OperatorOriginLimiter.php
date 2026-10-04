<?php

namespace App\Modules\Admin\Application\SignIn;

use App\Modules\Identity\Application\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Failures from one origin across any operator emails (security baseline,
 * Lockout; spec 018, R-9). Separate from the application's limiter.
 */
final class OperatorOriginLimiter
{
    public function __construct(private readonly Request $request) {}

    public function secondsBlocked(): ?int
    {
        $key = $this->key();

        return RateLimiter::tooManyAttempts($key, (int) config('admin.auth.lockout.origin.failures'))
            ? max(1, RateLimiter::availableIn($key))
            : null;
    }

    public function hit(): void
    {
        RateLimiter::hit($this->key(), 60 * (int) config('admin.auth.lockout.origin.decay_minutes'));
    }

    private function key(): string
    {
        return 'admin-origin:'.(AuditLog::coarsen($this->request->ip()) ?? 'unknown');
    }
}
