<?php

namespace App\Modules\Wallets\Domain\Transactions;

use DateTimeImmutable;

/**
 * A Transaction as the application sees it: every column, no framework type.
 * For a transfer `walletId` is the source and `counterWalletId` the
 * destination (ADR-0015); `counterChangeSeq` is the destination scope's
 * stamp and never goes on the wire. `recurringRuleId` and `occurrenceId` are
 * read-only links to the occurrence this transaction confirmed (spec 014).
 */
final readonly class TransactionRecord
{
    public function __construct(
        public string $id,
        public string $walletId,
        public TransactionKind $kind,
        public string $date,
        public int $amountMinor,
        public string $currency,
        public ?string $categoryId,
        public ?string $counterWalletId,
        public ?string $description,
        public ?string $memo,
        public ?string $counterparty,
        public bool $cleared,
        public string $createdBy,
        public int $version,
        public int $changeSeq,
        public ?int $counterChangeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
        public ?string $recurringRuleId = null,
        public ?string $occurrenceId = null,
        public ?string $sharedBudgetId = null,
    ) {}

    public function isTombstone(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isTransfer(): bool
    {
        return $this->kind === TransactionKind::Transfer;
    }

    /**
     * Every wallet the transaction posts to: the home wallet, then a
     * transfer's destination.
     *
     * @return list<string>
     */
    public function walletIds(): array
    {
        return $this->counterWalletId === null ? [$this->walletId] : [$this->walletId, $this->counterWalletId];
    }

    /**
     * Whether the transaction belongs to the wallet's list: its own, or a
     * transfer into it.
     */
    public function touches(string $walletId): bool
    {
        return in_array($walletId, $this->walletIds(), true);
    }

    /**
     * The members a client writes, in the shape a write is compared against.
     *
     * @return array{kind: string, date: string, amount_minor: int, currency: string, category_id: ?string, counter_wallet_id: ?string, description: ?string, memo: ?string, counterparty: ?string, cleared: bool, shared_budget_id: ?string}
     */
    public function writableValues(): array
    {
        return [
            'kind' => $this->kind->value,
            'date' => $this->date,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'category_id' => $this->categoryId,
            'counter_wallet_id' => $this->counterWalletId,
            'description' => $this->description,
            'memo' => $this->memo,
            'counterparty' => $this->counterparty,
            'cleared' => $this->cleared,
            'shared_budget_id' => $this->sharedBudgetId,
        ];
    }
}
