<?php

namespace App\Modules\Identity\Application\Jobs;

use App\Modules\Identity\Application\Deletion\PurgeAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one account's purge (spec 022, R-12): unique per account, five
 * attempts with growing back-off, up to 30 minutes a run. A failed run
 * leaves the account `purging`; the hourly sweep dispatches it again and
 * the purge resumes from its first incomplete step.
 */
final class PurgeAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 1800;

    /** Seconds a unique lock outlives a worker that died mid-run. */
    public int $uniqueFor = 3600;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(public readonly string $userId) {}

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(PurgeAccount $purge): void
    {
        $purge->run($this->userId);
    }
}
