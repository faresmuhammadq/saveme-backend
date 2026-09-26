<?php

namespace App\Modules\Identity\Application\Verification;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\VerificationLinkInvalid;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a verification link (FR-011): single use, within 24 hours, and only
 * the newest one. Works without a session.
 */
final class VerifyAddress
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
    ) {}

    public function handle(string $secret): void
    {
        DB::transaction(function () use ($secret): void {
            $userId = $this->links->redeem($secret, LinkKind::VerifyAddress) ?? throw new VerificationLinkInvalid;

            if ($this->users->markVerified($userId)) {
                $this->audit->record(AuditRecord::byUser(AuditEvent::AddressVerified, $userId, SubjectType::User, $userId));
            }
        });
    }
}
