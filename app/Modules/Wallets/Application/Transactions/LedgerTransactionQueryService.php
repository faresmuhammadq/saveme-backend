<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Wallets\Application\TransactionQueryService;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;

/**
 * Answers other modules' questions about transactions from the ledger
 * (spec 012, R-12). Replaces the stand-in that said nobody had recorded one.
 */
final class LedgerTransactionQueryService implements TransactionQueryService
{
    public function __construct(private readonly TransactionRepository $transactions) {}

    public function hasLiveTransactionsRecordedBy(string $userId): bool
    {
        return $this->transactions->anyLiveRecordedBy($userId);
    }

    public function authorAndCurrencyOf(string $transactionId): ?array
    {
        $transaction = $this->transactions->find($transactionId);

        if ($transaction === null || $transaction->isTombstone()) {
            return null;
        }

        return [
            'author_id' => $transaction->createdBy,
            'currency' => $transaction->currency,
            'date' => $transaction->date,
            'category_id' => $transaction->categoryId,
            'kind' => $transaction->kind->value,
        ];
    }
}
