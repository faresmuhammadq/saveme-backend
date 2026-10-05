<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\DeletionCompletedDetail;
use App\Modules\Identity\Application\Mail\AccountDeletedMail;
use App\Modules\Identity\Application\Profiles\Profiles;
use App\Modules\Identity\Application\Profiles\SaveProfile;
use App\Modules\Identity\Application\Sessions\EndSessions;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Identity\Domain\Ports\AccountPurgeRepository;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Ports\ExportStore;
use App\Modules\Identity\Domain\Ports\IdentityEraser;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\SuccessorChoiceRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\ProfileVersionConflict;
use App\Modules\Identity\Domain\Sessions\EndReason;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Deletes an account whose grace period has ended (spec 022, R-12, A-16):
 * ordered, idempotent, recorded steps. `begin` moves the account from
 * `pending_deletion` to `purging` and ends every session in one unit of
 * work; each later step is skipped once recorded, and every step is a no-op
 * on state it already finished, so a run that crashed between a step's
 * commit and its record simply does it again. The order is the contract:
 * the display name is erased (publishing `ProfileUpdated`) before any
 * membership is tombstoned; invitations are revoked before the data key is
 * destroyed; the completion mail is sent, synchronously, before the address
 * goes; the identity is erased last.
 *
 * Modules supply their steps as AccountPurgeParticipants. The purge acts as
 * the departing person through those modules' existing services while their
 * role is valid; only session ends, subscription ends and the completion
 * entry are the system's (FR-064). It never holds the user row lock while a
 * participant runs (ADR-0017 rule 5). Nothing about names, addresses,
 * amounts or notes is logged: ids and step names only.
 */
final class PurgeAccount
{
    /**
     * @param  iterable<AccountPurgeParticipant>  $participants
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountPurgeRepository $purges,
        private readonly EndSessions $sessions,
        private readonly Profiles $profiles,
        private readonly SaveProfile $saveProfile,
        private readonly ProfileRepository $profileRepository,
        private readonly ExportRepository $exports,
        private readonly ExportStore $exportFiles,
        private readonly SuccessorChoiceRepository $choices,
        private readonly Accounts $accounts,
        private readonly IdentityEraser $eraser,
        private readonly AuditLog $audit,
        private readonly DeletionPlanner $planner,
        private readonly iterable $participants = [],
    ) {}

    public function run(string $userId): void
    {
        // One run per account at a time, beyond the unique job: a second caller leaves the work to the first.
        if (! $this->purges->tryClaim($userId)) {
            return;
        }

        try {
            $this->runClaimed($userId);
        } finally {
            $this->purges->release($userId);
        }
    }

    private function runClaimed(string $userId): void
    {
        $now = now()->toImmutable();

        if (! $this->begin($userId, $now)) {
            return;
        }

        $progress = $this->purges->find($userId) ?? throw new RuntimeException('The purge has no progress record.');
        $counts = $progress['counts'];
        $done = $progress['last_step'];
        $context = new PurgeContext($userId, $now, $this->planner);

        Log::info('account_purge_resumed', ['user_id' => $userId, 'after_step' => $done->value]);

        foreach (PurgeStep::cases() as $step) {
            if (! $done->isBefore($step)) {
                continue;
            }

            $counts = $counts->plus($this->perform($step, $userId, $context));

            if ($step === PurgeStep::EraseIdentity) {
                Log::info('account_purge_completed', ['user_id' => $userId]);

                return;
            }

            $this->purges->record($userId, $step, $counts, now()->toImmutable());
            Log::info('account_purge_step', ['user_id' => $userId, 'step' => $step->value]);
        }
    }

    /**
     * The first step, in one unit of work under the account row's lock:
     * pending and due → purging, the progress row, every session ended (end
     * reason `account_deleted`, actor `system`), the web sessions dropped.
     * An account already `purging` just resumes; any other state is left
     * alone.
     *
     * @return bool whether there is a purge to continue
     */
    private function begin(string $userId, DateTimeImmutable $now): bool
    {
        $state = $this->users->find($userId)?->state;

        if ($state === AccountState::Purging) {
            $this->purges->begin($userId, $now);

            return true;
        }

        if ($state !== AccountState::PendingDeletion) {
            return false;
        }

        return DB::transaction(function () use ($userId, $now): bool {
            if (! $this->users->markPurging($userId, $now)) {
                return false;
            }

            $this->purges->begin($userId, $now);
            $this->sessions->endAllFor($userId, EndReason::AccountDeleted, actor: ActorType::System);
            DB::table('sessions')->where('user_id', $userId)->delete();

            return true;
        });
    }

    private function perform(PurgeStep $step, string $userId, PurgeContext $context): PurgeCounts
    {
        return match ($step) {
            PurgeStep::Begin => new PurgeCounts,
            PurgeStep::EraseName => $this->eraseName($userId),
            PurgeStep::Exports => $this->removeExports($userId),
            PurgeStep::CompletionMail => $this->sendCompletionMail($userId),
            PurgeStep::EraseIdentity => $this->eraseIdentity($userId),
            default => $this->participate($step, $userId, $context),
        };
    }

    /**
     * Erases the display name through the profile's own save, so
     * `ProfileUpdated` names `display_name` after commit (ADR-0021, B14a).
     * A person with no name publishes nothing.
     */
    private function eraseName(string $userId): PurgeCounts
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $profile = $this->profiles->find($userId);

            if ($profile->displayName === null) {
                return new PurgeCounts;
            }

            try {
                $this->saveProfile->handle($userId, [...$profile->writableValues(), 'display_name' => null], $profile->version, fn (): array => []);

                return new PurgeCounts;
            } catch (ProfileVersionConflict $conflict) {
                if ($attempt === 3) {
                    throw $conflict;
                }
            }
        }

        return new PurgeCounts;
    }

    private function participate(PurgeStep $step, string $userId, PurgeContext $context): PurgeCounts
    {
        $counts = new PurgeCounts;
        $found = false;

        foreach ($this->participants as $participant) {
            if ($participant->step() === $step) {
                $found = true;
                $counts = $counts->plus($participant->purge($userId, $context));
            }
        }

        if (! $found) {
            throw new RuntimeException("No participant registered for the purge step [{$step->value}].");
        }

        return $counts;
    }

    private function removeExports(string $userId): PurgeCounts
    {
        $this->exportFiles->deleteAllFor($userId);
        $this->exports->deleteAllFor($userId);
        $this->choices->deleteFor($userId);

        return new PurgeCounts;
    }

    /**
     * One mail, sent here and now (never queued, so no address sits in the
     * jobs table), before the address and key are destroyed; stamped so a
     * resumed purge never sends it twice.
     */
    private function sendCompletionMail(string $userId): PurgeCounts
    {
        $progress = $this->purges->find($userId);

        if ($progress !== null && $progress['mail_sent_at'] !== null) {
            return new PurgeCounts;
        }

        Mail::to($this->accounts->address($userId))
            ->locale($this->profileRepository->localeOf($userId))
            ->send(new AccountDeletedMail);

        $this->purges->markMailSent($userId, now()->toImmutable());

        return new PurgeCounts;
    }

    /**
     * Last, in one transaction: the profile tombstoned then removed, every
     * dependant and the data key deleted, the users row reduced to a husk,
     * `deletion_completed` written, the progress row dropped.
     */
    private function eraseIdentity(string $userId): PurgeCounts
    {
        $counts = ($this->purges->find($userId) ?? throw new RuntimeException('The purge has no progress record.'))['counts'];

        DB::transaction(function () use ($userId, $counts): void {
            $now = now()->toImmutable();
            $lookup = $this->users->lookupOf($userId);

            $this->eraser->eraseProfile($userId, $now);
            $this->eraser->eraseDependants($userId, $lookup);
            $this->users->toHusk($userId, $now);

            $this->audit->record(new AuditRecord(AuditEvent::DeletionCompleted, ActorType::System, null, SubjectType::User, $userId, new DeletionCompletedDetail($counts)));
            $this->purges->delete($userId);
        });

        return new PurgeCounts;
    }
}
