<?php

namespace App\Modules\Wallets\Domain\Activity;

use App\Modules\Wallets\Domain\Membership\Role;
use DateTimeImmutable;

/**
 * One recorded act in a wallet: identifiers only, no amount, note, name or
 * address (ADR-0020 rule 2).
 */
final readonly class ActivityRecord
{
    public function __construct(
        public string $id,
        public string $walletId,
        public DateTimeImmutable $occurredAt,
        public ?string $actorUserId,
        public ActivityAction $action,
        public ActivitySubject $subject,
        public string $subjectId,
        public ?Role $role,
    ) {}
}
