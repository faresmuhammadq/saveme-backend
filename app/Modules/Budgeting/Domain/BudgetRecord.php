<?php

namespace App\Modules\Budgeting\Domain;

use DateTimeImmutable;

/**
 * One person's budget as stored (spec 015, data-model): a limit for one
 * expense category, or all expenses when `categoryId` is null, in one
 * currency, per Budget Period.
 */
final readonly class BudgetRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $categoryId,
        public PeriodType $periodType,
        public int $amountMinor,
        public string $currency,
        public ?DateTimeImmutable $archivedAt,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function isTombstoned(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /**
     * The members a whole save writes.
     *
     * @return array{category_id: ?string, period_type: string, amount_minor: int, currency: string}
     */
    public function writableValues(): array
    {
        return [
            'category_id' => $this->categoryId,
            'period_type' => $this->periodType->value,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
        ];
    }
}
