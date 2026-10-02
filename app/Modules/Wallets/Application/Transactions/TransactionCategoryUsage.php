<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Wallets\Application\Categories\CategoryUsage;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;

/**
 * A category any transaction references, live or tombstoned, is in use
 * (spec 012, R-8): a tombstone can be restored, so deleting the category
 * would orphan it. Postings mirror `transactions.category_id`, so counting
 * transactions covers them.
 */
final class TransactionCategoryUsage implements CategoryUsage
{
    public function __construct(private readonly TransactionRepository $transactions) {}

    public function isUsed(string $categoryId): bool
    {
        return $this->transactions->categoryUsed($categoryId);
    }

    public function usedAmong(array $categoryIds): array
    {
        return $this->transactions->categoriesUsedAmong($categoryIds);
    }
}
