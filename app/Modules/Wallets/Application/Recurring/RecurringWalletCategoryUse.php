<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Application\Categories\WalletCategoryUse;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;

/**
 * Recurring rules (category and payout category) and occurrences of a
 * wallet, live or tombstoned, that use a category (spec 014, R-7): a rule
 * still needs its Category Reference even after the last transaction moved
 * away from the category.
 */
final class RecurringWalletCategoryUse implements WalletCategoryUse
{
    public function __construct(
        private readonly RecurringRuleRepository $rules,
        private readonly OccurrenceRepository $occurrences,
    ) {}

    public function usedIn(string $walletId, string $categoryId, ?string $exceptTransactionId = null): bool
    {
        return $this->rules->anyInWalletUsing($walletId, $categoryId) || $this->occurrences->anyInWalletUsing($walletId, $categoryId);
    }
}
