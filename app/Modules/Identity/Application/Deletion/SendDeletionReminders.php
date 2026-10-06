<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Jobs\SendDeletionReminderMail;
use App\Modules\Identity\Domain\Events\DeletionReminderDue;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Support\Facades\DB;

/**
 * The daily reminder run (spec 022, FR-058): once, when the purge is three
 * days or less away. The stamp and the queued mail share one unit of work;
 * cancelling clears the stamp, so a new request is reminded again.
 */
final class SendDeletionReminders
{
    private const LIMIT = 500;

    public function __construct(private readonly UserRepository $users) {}

    /**
     * @return int reminders queued
     */
    public function run(): int
    {
        $now = now()->toImmutable();
        $queued = 0;

        foreach ($this->users->dueReminders($now, (int) config('identity.deletion_reminder_days'), self::LIMIT) as $userId) {
            $queued += DB::transaction(function () use ($userId, $now): int {
                if (! $this->users->markReminded($userId, $now)) {
                    return 0;
                }

                SendDeletionReminderMail::dispatch($userId)->afterCommit();

                $scheduledFor = $this->users->find($userId)?->deletionScheduledFor;

                if ($scheduledFor !== null) {
                    event(new DeletionReminderDue(
                        ...Events::stamp(),
                        userId: $userId,
                        deletionScheduledFor: $scheduledFor->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                    ));
                }

                return 1;
            });
        }

        return $queued;
    }
}
