<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;

/**
 * The category rules of a rule or occurrence write, as B6 has them for a
 * transaction (spec 014, FR-003, FR-014): a category the writer may use is
 * their own or a platform one, live (archived allowed) and of the right
 * kind; one already on the stored record stays, whoever's it is. The rows
 * are share-locked by the caller before any wallet (FR-026).
 */
final class RecurringCategoryChecks
{
    public function __construct(private readonly CategoryRepository $categories) {}

    /**
     * @param  string  $kind  `income` or `expense`, what the category must be
     * @param  string  $pointer  the member, e.g. `/category_id`
     */
    public function error(string $actorId, string $kind, ?string $categoryId, ?string $storedCategoryId, string $pointer): ?FieldError
    {
        if ($categoryId === null || $categoryId === $storedCategoryId) {
            return null;
        }

        $platform = $this->categories->findPlatformForShare($categoryId);

        if ($platform !== null) {
            return $platform->kind->value === $kind ? null : new FieldError($pointer, ErrorCode::InvalidEnumValue);
        }

        $own = $this->categories->findForShare($categoryId);

        if ($own !== null && $own->ownerId === $actorId && ! $own->isTombstone() && $own->kind->value === $kind) {
            return null;
        }

        return new FieldError($pointer, ErrorCode::InvalidEnumValue);
    }
}
