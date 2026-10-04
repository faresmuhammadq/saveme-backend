<?php

namespace App\Modules\Admin\Infrastructure\Repositories;

use App\Modules\Admin\Domain\Ports\OperatorFailureCounter;
use App\Modules\Admin\Infrastructure\Models\OperatorSignInFailure;
use Illuminate\Support\Facades\DB;

/**
 * Consecutive failures and the lock they cause, per email lookup value
 * (security baseline, Lockout; spec 018, R-9). Thresholds are the baseline's,
 * read from config/admin.php.
 */
final class EloquentOperatorFailureCounter implements OperatorFailureCounter
{
    public function lockedFor(string $lookup): ?int
    {
        $row = OperatorSignInFailure::query()->find($lookup);

        if ($row === null || $row->locked_until === null || $row->locked_until->isPast()) {
            return null;
        }

        return (int) ceil(now()->diffInSeconds($row->locked_until, true));
    }

    public function recordFailure(string $lookup): ?int
    {
        // One upsert, so concurrent failures are all counted.
        $row = DB::selectOne(
            'insert into operator_sign_in_failures (lookup, consecutive_failures, last_failed_at) values (?, 1, ?)
             on conflict (lookup) do update
                set consecutive_failures = operator_sign_in_failures.consecutive_failures + 1, last_failed_at = excluded.last_failed_at
             returning consecutive_failures',
            [$lookup, now()->format('Y-m-d H:i:s.uP')],
        );
        $failures = (int) $row->consecutive_failures;

        $minutes = $this->lockMinutesAt($failures);

        if ($minutes === null) {
            return null;
        }

        $minutes = min($minutes, (int) config('admin.auth.lockout.cap_minutes'));
        OperatorSignInFailure::query()->whereKey($lookup)->update(['locked_until' => now()->addMinutes($minutes)->format('Y-m-d H:i:s.uP')]);

        return $minutes;
    }

    public function clear(string $lookup): void
    {
        OperatorSignInFailure::query()->whereKey($lookup)->delete();
    }

    /**
     * Exactly at a threshold, that threshold's duration; past the last one,
     * every further failure re-applies the last (longest) duration.
     */
    private function lockMinutesAt(int $failures): ?int
    {
        /** @var list<array{failures: int, minutes: int}> $thresholds */
        $thresholds = config('admin.auth.lockout.account');
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
