<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets;

use DateTimeImmutable;

/**
 * One person's membership of a Shared Budget. `memberChangeSeq` is the stamp
 * from their own user scope (ADR-0017 rule 2) and never goes on the wire.
 */
final readonly class SharedBudgetMemberRecord
{
    public function __construct(
        public string $id,
        public string $sharedBudgetId,
        public string $userId,
        public SharedBudgetRole $role,
        public bool $readOnly,
        public int $version,
        public int $changeSeq,
        public int $memberChangeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function isOwner(): bool
    {
        return $this->role === SharedBudgetRole::Owner;
    }
}
