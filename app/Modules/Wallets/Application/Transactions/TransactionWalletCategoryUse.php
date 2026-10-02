<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Wallets\Application\Categories\WalletCategoryUse;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;

/**
 * Transactions of a wallet, live or tombstoned, that use a category (spec
 * 012, R-8).
 */
final class TransactionWalletCategoryUse implements WalletCategoryUse
{
    public function __construct(private readonly TransactionRepository $transactions) {}

    public function usedIn(string $walletId, string $categoryId, ?string $exceptTransactionId = null): bool
    {
        return $this->transactions->anyInWalletUsing($walletId, $categoryId, $exceptTransactionId ?? '00000000-0000-0000-0000-000000000000');
    }
}
