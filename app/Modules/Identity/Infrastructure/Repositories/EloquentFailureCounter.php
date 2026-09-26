<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\FailureCounter;
use App\Modules\Identity\Infrastructure\Models\SignInFailure;
use Illuminate\Support\Facades\DB;

/**
 * Consecutive failures and the lock they cause, per address lookup value
 * (security baseline, Lockout; spec 007, R-9).
 */
final class EloquentFailureCounter implements FailureCounter
{
    public function lockedFor(string $lookup): ?int
    {
        $row = SignInFailure::query()->find($lookup);

        if ($row === null || $row->locked_until === null || $row->locked_until->isPast()) {
            return null;
        }

        return (int) ceil(now()->diffInSeconds($row->locked_until, true));
    }

    public function recordFailure(string $lookup): ?int
    {
        // One upsert, so concurrent failures are all counted.
        $row = DB::selectOne(
            'insert into sign_in_failures (lookup, consecutive_failures, last_failed_at) values (?, 1, ?)
             on conflict (lookup) do update
                set consecutive_failures = sign_in_failures.consecutive_failures + 1, last_failed_at = excluded.last_failed_at
             returning consecutive_failures',
            [$lookup, now()->format('Y-m-d H:i:s.uP')],
        );
        $failures = (int) $row->consecutive_failures;

        $minutes = $this->lockMinutesAt($failures);

        if ($minutes === null) {
            return null;
        }

        $minutes = min($minutes, (int) config('identity.lockout.cap_minutes'));
        SignInFailure::query()->whereKey($lookup)->update(['locked_until' => now()->addMinutes($minutes)->format('Y-m-d H:i:s.uP')]);

        return $minutes;
    }

    public function clear(string $lookup): void
    {
        SignInFailure::query()->whereKey($lookup)->delete();
    }

    /**
     * The lock a failure count earns: exactly at a threshold, that threshold's
     * duration; past the last threshold, every further failure re-applies the
     * last (and longest) duration. Between thresholds, no new lock.
     */
    private function lockMinutesAt(int $failures): ?int
    {
        /** @var list<array{failures: int, minutes: int}> $thresholds */
        $thresholds = config('identity.lockout.account');
        $last = end($thresholds);

        if ($last === false) {
            return null;
        }

        foreach ($thresholds as $threshold) {
            if ($failures === (int) $threshold['failures']) {
                return (int) $threshold['minutes'];
            }
        }

        return $failures > (int) $last['failures'] ? (int) $last['minutes'] : null;
    }
}
