<?php

namespace App\Modules\Wallets\Application\Categories;

/**
 * Asks every registered participant whether a category is used anywhere in a
 * wallet: the ledger's transactions, recurring rules and occurrences.
 * B6's withdrawal of a reference calls this, so it can never withdraw a
 * reference a rule still needs (spec 014, A-36). With no participant nothing
 * is in use.
 */
final class WalletCategoryUses
{
    /**
     * @param  iterable<WalletCategoryUse>  $participants
     */
    public function __construct(private readonly iterable $participants = []) {}

    public function usedIn(string $walletId, string $categoryId, ?string $exceptTransactionId = null): bool
    {
        foreach ($this->participants as $participant) {
            if ($participant->usedIn($walletId, $categoryId, $exceptTransactionId)) {
                return true;
            }
        }

        return false;
    }
}
