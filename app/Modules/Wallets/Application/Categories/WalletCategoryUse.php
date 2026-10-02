<?php

namespace App\Modules\Wallets\Application\Categories;

/**
 * A module with records inside a wallet that name a category registers one of
 * these (tagged with this interface's name) so the wallet's Category
 * Reference is withdrawn only when nothing in the wallet uses the category
 * any more (spec 014, R-7, FR-027): B6 transactions, B7 rules and occurrences,
 * live or tombstoned, B8 budgets.
 */
interface WalletCategoryUse
{
    /**
     * Whether any record of this module in the wallet, live or tombstoned,
     * references the category, other than the transaction named.
     */
    public function usedIn(string $walletId, string $categoryId, ?string $exceptTransactionId = null): bool;
}
