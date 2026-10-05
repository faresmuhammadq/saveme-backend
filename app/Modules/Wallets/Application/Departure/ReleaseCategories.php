<?php

namespace App\Modules\Wallets\Application\Departure;

use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Wallets\Application\Categories\CategoryService;

/**
 * Wallets' categories step of an account purge (spec 022, FR-046): the
 * departing person's categories that no record uses are removed, the others
 * archived and kept until nothing uses them.
 */
final class ReleaseCategories implements AccountPurgeParticipant
{
    public function __construct(private readonly CategoryService $categories) {}

    public function step(): PurgeStep
    {
        return PurgeStep::Categories;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        $released = $this->categories->releaseForDepartedUser($userId);

        return PurgeCounts::of('categories_deleted', $released['deleted'])->plus(PurgeCounts::of('categories_kept', $released['kept']));
    }
}
