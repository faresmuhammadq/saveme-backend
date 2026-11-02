<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets;

use DateTimeImmutable;

/**
 * One transaction's appearance in a Shared Budget (ADR-0027 rules 5 to 8). It
 * holds no wallet, balance, memo or counterparty, by construction.
 */
final readonly class SharedBudgetEntryRecord
{
    public function __construct(
        public string $id,
        public string $sharedBudgetId,
        public string $transactionId,
        public string $createdBy,
        public string $categoryId,
        public string $date,
        public int $amountMinor,
        public ?string $description,
        public ?DateTimeImmutable $detachedAt,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function isDetached(): bool
    {
        return $this->detachedAt !== null;
    }

    public function isTombstoned(): bool
    {
        return $this->deletedAt !== null;
    }
}
