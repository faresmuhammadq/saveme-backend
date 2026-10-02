<?php

namespace App\Modules\Wallets\Application\Categories;

use App\Modules\Wallets\Domain\Categories\PlatformCategoryRecord;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;

/**
 * The platform-owned default set (ADR-0014): one query over a small table.
 */
final class PlatformCategoryQueryService
{
    public function __construct(private readonly CategoryRepository $categories) {}

    /**
     * Every platform category, retired ones included, in position order.
     *
     * @return list<PlatformCategoryRecord>
     */
    public function all(): array
    {
        return $this->categories->allPlatform();
    }
}
