<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * One category's sum in a range. `label` is the grouping key, never display
 * text: a platform category's `system_key`, else the category id.
 */
final readonly class CategoryItem
{
    public function __construct(
        public string $label,
        public string $categoryId,
        public ?string $systemKey,
        public Money $value,
        public ?Money $planned,
    ) {}
}
