<?php

namespace App\Modules\Notifications\Application\Departure;

use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use App\Modules\Notifications\Domain\Ports\PreferencesRepository;
use App\Modules\Notifications\Domain\Ports\RegistrationRepository;
use Illuminate\Support\Facades\DB;

/**
 * Notifications' step of an account purge (spec 023, FR-012, FR-044): the
 * person's push registrations, preferences, notifications and their
 * deliveries (by cascade) are deleted. Sessions already ended at the purge's
 * start removed the registrations through `DeviceSessionEnded`; this makes
 * sure nothing is left whatever the queue did. Safe to repeat. Contributes
 * no count: the completion entry's vocabulary does not change (FR-045).
 */
final class NotificationsPurge implements AccountPurgeParticipant
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly PreferencesRepository $preferences,
        private readonly NotificationRepository $notifications,
    ) {}

    public function step(): PurgeStep
    {
        return PurgeStep::Notifications;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        DB::transaction(function () use ($userId): void {
            $this->registrations->deleteAllFor($userId);
            $this->preferences->deleteFor($userId);
            $this->notifications->deleteAllFor($userId);
        });

        return new PurgeCounts;
    }
}
