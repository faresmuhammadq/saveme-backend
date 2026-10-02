<?php

namespace App\Modules\Wallets\Application\Categories;

use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryNotEligible;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Wallets' answer to "may this person budget this category?" (spec 015,
 * FR-003): an expense category, live (archived or retired allowed), that is
 * the person's own or a platform category. The row is share-locked for the
 * caller's transaction, so a concurrent delete of the category and the
 * budget that names it cannot both succeed (ADR-0017 rule 5: category
 * first, then the user counter).
 */
final class BudgetCategoryCheck
{
    public function __construct(private readonly CategoryRepository $categories) {}

    /**
     * @throws CategoryNotEligible
     */
    public function lockEligible(string $userId, string $categoryId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The category lock lasts until the caller\'s transaction ends.');
        }

        $platform = $this->categories->findPlatformForShare($categoryId);

        if ($platform !== null) {
            if ($platform->kind !== CategoryKind::Expense) {
                throw new CategoryNotEligible;
            }

            return;
        }

        $own = $this->categories->findForShare($categoryId);

        if ($own === null || $own->ownerId !== $userId || $own->isTombstone() || $own->kind !== CategoryKind::Expense) {
            throw new CategoryNotEligible;
        }
    }
}
