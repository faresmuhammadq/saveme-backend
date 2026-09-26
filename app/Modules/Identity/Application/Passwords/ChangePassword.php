<?php

namespace App\Modules\Identity\Application\Passwords;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\PasswordChangedDetail;
use App\Modules\Identity\Application\Mail\PasswordChangedMail;
use App\Modules\Identity\Application\Sessions\EndSessions;
use App\Modules\Identity\Application\SignIn\CredentialCheck;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\InvalidPassword;
use App\Modules\Identity\Domain\Sessions\EndReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Changes the password while signed in (FR-035, FR-036): every other session
 * ends, the one making the change stays — the baseline's "sign everything
 * else out" lever — and outstanding reset links die.
 */
final class ChangePassword
{
    public function __construct(
        private readonly CredentialCheck $credentials,
        private readonly NewPassword $policy,
        private readonly UserRepository $users,
        private readonly EndSessions $sessions,
        private readonly LinkRepository $links,
        private readonly ProfileRepository $profiles,
        private readonly Accounts $accounts,
        private readonly AuditLog $audit,
    ) {}

    public function handle(string $userId, string $currentSessionId, string $currentPassword, string $newPassword): void
    {
        $this->credentials->verifyForUser($userId, $currentPassword);

        if ($newPassword === $currentPassword) {
            throw new InvalidPassword('new_password');
        }

        $this->policy->assertAcceptable($userId, $newPassword, 'new_password');

        DB::transaction(function () use ($userId, $currentSessionId, $newPassword): void {
            $this->users->updatePasswordHash($userId, Hash::make($newPassword));
            $this->sessions->endAllFor($userId, EndReason::PasswordChanged, except: $currentSessionId);
            $this->links->supersedeAll($userId, LinkKind::ResetPassword);

            $this->audit->record(AuditRecord::byUser(AuditEvent::PasswordChanged, $userId, SubjectType::User, $userId, new PasswordChangedDetail('change')));
        });

        Mail::to($this->accounts->address($userId))
            ->locale($this->profiles->localeOf($userId))
            ->queue(new PasswordChangedMail);
    }
}
