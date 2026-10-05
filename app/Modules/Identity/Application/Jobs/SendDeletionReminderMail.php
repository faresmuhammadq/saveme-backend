<?php

namespace App\Modules\Identity\Application\Jobs;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Mail\DeletionReminderMail;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Reminds a person that their account will be deleted soon (spec 022,
 * FR-058). The address is opened here, at send time, and the job is
 * encrypted at rest. A deletion cancelled meanwhile sends nothing.
 */
final class SendDeletionReminderMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $userId) {}

    public function handle(UserRepository $users, Accounts $accounts, ProfileRepository $profiles): void
    {
        $user = $users->find($this->userId);

        if ($user === null || $user->state !== AccountState::PendingDeletion || $user->deletionScheduledFor === null) {
            return;
        }

        Mail::to($accounts->address($this->userId))
            ->locale($profiles->localeOf($this->userId))
            ->send(new DeletionReminderMail($user->deletionScheduledFor, $profiles->timezoneOf($this->userId)));
    }
}
