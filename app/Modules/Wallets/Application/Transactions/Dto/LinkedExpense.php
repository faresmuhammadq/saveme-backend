<?php

namespace App\Modules\Wallets\Application\Transactions\Dto;

/**
 * What a Shared Budget may know of a transaction (ADR-0027 rule 6): no
 * wallet, balance, memo or counterparty.
 */
final readonly class LinkedExpense
{
    public function __construct(
        public string $transactionId,
        public string $authorId,
        public string $categoryId,
        public string $date,
        public int $amountMinor,
        public ?string $description,
    ) {}
}
