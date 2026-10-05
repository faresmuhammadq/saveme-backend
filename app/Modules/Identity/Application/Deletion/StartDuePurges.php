<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Jobs\PurgeAccountJob;
use App\Modules\Identity\Domain\Events\AccountPurgeStalled;
use App\Modules\Identity\Domain\Ports\AccountPurgeRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Support\Facades\DB;

/**
 * The hourly purge run (spec 022, FR-051, FR-063, R-14): starts a purge for
 * every account whose grace period has ended and resumes every one under
 * way, reports a purge unfinished after 24 hours once, and deletes the
 * husks of accounts purged 400 days ago. Nothing is purged twice: the job is
 * unique per account and `begin` is conditional.
 */
final class StartDuePurges
{
    private const LIMIT = 200;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountPurgeRepository $purges,
    ) {}

    /**
     * @return array{dispatched: int, stalled: int, husks_deleted: int}
     */
    public function run(): array
    {
        $now = now()->toImmutable();
        $dispatched = 0;

        foreach ($this->users->purgeCandidates($now, self::LIMIT) as $userId) {
            PurgeAccountJob::dispatch($userId);
            $dispatched++;
        }

        $hours = (int) config('identity.purge_stall_hours');
        $stalled = 0;

        foreach ($this->purges->stalledBefore($now->modify("-{$hours} hours")) as $purge) {
            $stalled += DB::transaction(function () use ($purge, $now): int {
                if (! $this->purges->markStallNotified($purge['user_id'], $now)) {
                    return 0;
                }

                event(new AccountPurgeStalled(...Events::stamp(), userId: $purge['user_id'], startedAt: $purge['started_at']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')));

                return 1;
            });
        }

        $husks = $this->users->deleteHusksBefore($now->modify('-'.(int) config('identity.husk_days').' days'));

        return ['dispatched' => $dispatched, 'stalled' => $stalled, 'husks_deleted' => $husks];
    }
}
