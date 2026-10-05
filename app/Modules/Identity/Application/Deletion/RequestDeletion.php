<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Mail\DeletionScheduledMail;
use App\Modules\Identity\Application\SignIn\CredentialCheck;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Events\UserDeletionRequested;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\SuccessorChoiceRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\InvalidSuccessorChoice;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Starts the 30-day deletion grace period (FR-050 to FR-052), confirmed with
 * the password. Asking again returns the date already set; the clock never
 * resets. The purge itself is spec 022's (PurgeAccount).
 */
final class RequestDeletion
{
    public function __construct(
        private readonly CredentialCheck $credentials,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly Accounts $accounts,
        private readonly AuditLog $audit,
        private readonly DeletionPlanner $planner,
        private readonly SuccessorChoiceRepository $choices,
    ) {}

    /**
     * @param  list<array{wallet_id: string, member_user_id: string}>|null  $successors  the new owners chosen for shared wallets (spec 022): null leaves any stored choices, a list replaces them
     *
     * @throws InvalidSuccessorChoice
     */
    public function handle(string $userId, string $password, ?array $successors = null): DateTimeImmutable
    {
        // Checked before the password, so a malformed choice never counts as a failed attempt.
        if ($successors !== null) {
            $this->planner->validateChoices($userId, $successors);
        }

        $this->credentials->verifyForUser($userId, $password);

        [$scheduledFor, $isNew] = DB::transaction(function () use ($userId, $successors): array {
            $user = $this->users->find($userId) ?? throw new RuntimeException('No such account.');

            if ($user->state === AccountState::PendingDeletion && $user->deletionScheduledFor !== null) {
                if ($successors !== null) {
                    $this->choices->replace($userId, $successors, now()->toImmutable());
                }

                return [$user->deletionScheduledFor, false];
            }

            $requestedAt = now()->toImmutable();
            $scheduledFor = $requestedAt->addDays((int) config('identity.deletion_grace_days'));
            $this->users->scheduleDeletion($userId, $requestedAt, $scheduledFor);

            if ($successors !== null) {
                $this->choices->replace($userId, $successors, $requestedAt);
            }

            $this->audit->record(AuditRecord::byUser(AuditEvent::DeletionRequested, $userId, SubjectType::User, $userId));
            event(new UserDeletionRequested(...Events::stamp(), userId: $userId, scheduledFor: $scheduledFor->utc()->format('Y-m-d\TH:i:s.u\Z')));

            return [$scheduledFor->toDateTimeImmutable(), true];
        });

        if ($isNew) {
            Mail::to($this->accounts->address($userId))
                ->locale($this->profiles->localeOf($userId))
                ->queue((new DeletionScheduledMail($scheduledFor, $this->profiles->timezoneOf($userId)))->afterCommit());
        }

        return $scheduledFor;
    }
}
