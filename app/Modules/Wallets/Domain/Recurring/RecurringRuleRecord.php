<?php

namespace App\Modules\Wallets\Domain\Recurring;

use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use DateTimeImmutable;

/**
 * A Recurring Rule as the application sees it: every column, no framework
 * type. `autoConfirmFrom` is internal and never goes on the wire.
 *
 * @phpstan-type Values array{preset: string, status: string, kind: string, amount_minor: int, amount_basis: string, currency: string, category_id: ?string, counter_wallet_id: ?string, description: ?string, memo: ?string, counterparty: ?string, auto_confirm: bool, frequency: string, interval: int, anchor_day: ?int, start_date: string, end_date: ?string, occurrence_count: ?int, payout_round: ?int, payout_category_id: ?string}
 */
final readonly class RecurringRuleRecord
{
    public function __construct(
        public string $id,
        public string $walletId,
        public Preset $preset,
        public RuleStatus $status,
        public TransactionKind $kind,
        public int $amountMinor,
        public AmountBasis $amountBasis,
        public string $currency,
        public ?string $categoryId,
        public ?string $counterWalletId,
        public ?string $description,
        public ?string $memo,
        public ?string $counterparty,
        public bool $autoConfirm,
        public string $autoConfirmFrom,
        public Frequency $frequency,
        public int $interval,
        public ?int $anchorDay,
        public string $startDate,
        public ?string $endDate,
        public ?int $occurrenceCount,
        public ?int $payoutRound,
        public ?string $payoutCategoryId,
        public string $timezone,
        public string $createdBy,
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

    public function isTransfer(): bool
    {
        return $this->kind === TransactionKind::Transfer;
    }

    public function schedule(): RuleDates
    {
        return new RuleDates($this->frequency, $this->interval, $this->anchorDay, $this->startDate, $this->endDate, $this->occurrenceCount);
    }

    /**
     * The members a client writes, in the shape a write is compared against.
     *
     * @return Values
     */
    public function writableValues(): array
    {
        return [
            'preset' => $this->preset->value,
            'status' => $this->status->value,
            'kind' => $this->kind->value,
            'amount_minor' => $this->amountMinor,
            'amount_basis' => $this->amountBasis->value,
            'currency' => $this->currency,
            'category_id' => $this->categoryId,
            'counter_wallet_id' => $this->counterWalletId,
            'description' => $this->description,
            'memo' => $this->memo,
            'counterparty' => $this->counterparty,
            'auto_confirm' => $this->autoConfirm,
            'frequency' => $this->frequency->value,
            'interval' => $this->interval,
            'anchor_day' => $this->anchorDay,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'occurrence_count' => $this->occurrenceCount,
            'payout_round' => $this->payoutRound,
            'payout_category_id' => $this->payoutCategoryId,
        ];
    }
}
