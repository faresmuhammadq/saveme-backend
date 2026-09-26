<?php

namespace App\Modules\Identity\Application\Passwords;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\PasswordChangedDetail;
use App\Modules\Identity\Application\Sessions\EndSessions;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Ports\FailureCounter;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\ResetLinkInvalid;
use App\Modules\Identity\Domain\Sessions\EndReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Completes a password reset (FR-031): the new password, every session ended,
 * the sign-in lock cleared at once (the attacker does not have the mailbox),
 * and every other reset link dead. It does not sign the person in.
 */
final class ResetPassword
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly UserRepository $users,
        private readonly FailureCounter $failures,
        private readonly EndSessions $sessions,
        private readonly NewPassword $policy,
        private readonly AuditLog $audit,
    ) {}

    public function handle(string $secret, string $password): void
    {
        DB::transaction(function () use ($secret, $password): void {
            $userId = $this->links->redeem($secret, LinkKind::ResetPassword) ?? throw new ResetLinkInvalid;

            $this->policy->assertAcceptable($userId, $password, 'password');

            $this->users->updatePasswordHash($userId, Hash::make($password));
            $this->sessions->endAllFor($userId, EndReason::PasswordReset);
            $this->failures->clear($this->users->lookupOf($userId));
            $this->links->supersedeAll($userId, LinkKind::ResetPassword);

            $this->audit->record(AuditRecord::byUser(AuditEvent::PasswordChanged, $userId, SubjectType::User, $userId, new PasswordChangedDetail('reset')));
        });
    }
}
