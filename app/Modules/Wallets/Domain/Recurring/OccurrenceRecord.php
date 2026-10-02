<?php

namespace App\Modules\Wallets\Domain\Recurring;

use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use DateTimeImmutable;

/**
 * An Occurrence as the application sees it: every column, no framework type.
 *
 * @phpstan-type Values array{status: string, date: string, amount_minor: int, category_id: ?string, description: ?string, memo: ?string, counterparty: ?string, transaction_id: ?string}
 */
final readonly class OccurrenceRecord
{
    public function __construct(
        public string $id,
        public string $walletId,
        public string $recurringRuleId,
        public int $ordinal,
        public bool $isPayout,
        public string $scheduledDate,
        public string $date,
        public OccurrenceStatus $status,
        public TransactionKind $kind,
        public int $amountMinor,
        public string $currency,
        public ?string $categoryId,
        public ?string $counterWalletId,
        public ?string $description,
        public ?string $memo,
        public ?string $counterparty,
        public bool $edited,
        public ?string $transactionId,
        public ?AutoConfirmRefusal $autoConfirmRefusal,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function isTombstone(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Confirmed or skipped: a decision was taken about the round.
     */
    public function isResolved(): bool
    {
        return $this->status !== OccurrenceStatus::Scheduled;
    }

    /**
     * The members a client writes, in the shape a write is compared against.
     *
     * @return Values
     */
    public function writableValues(): array
    {
        return [
            'status' => $this->status->value,
            'date' => $this->date,
            'amount_minor' => $this->amountMinor,
            'category_id' => $this->categoryId,
            'description' => $this->description,
            'memo' => $this->memo,
            'counterparty' => $this->counterparty,
            'transaction_id' => $this->transactionId,
        ];
    }
}
