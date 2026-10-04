<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The expense or income half of a by-category report: the items sorted by
 * amount descending then label, and `total`, their exact sum. `planned` is
 * the sum of the items' planned amounts, null unless planned figures were asked for.
 */
final readonly class CategoryGroup
{
    /**
     * @param  list<CategoryItem>  $items
     */
    public function __construct(
        public Money $total,
        public ?Money $planned,
        public array $items,
    ) {}
}
