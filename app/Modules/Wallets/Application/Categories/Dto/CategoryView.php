<?php

namespace App\Modules\Wallets\Application\Categories\Dto;

use App\Modules\Wallets\Domain\Categories\CategoryRecord;

/**
 * A category as a read presents it: the record and whether it is in use.
 */
final readonly class CategoryView
{
    public function __construct(
        public CategoryRecord $category,
        public bool $inUse,
    ) {}
}
