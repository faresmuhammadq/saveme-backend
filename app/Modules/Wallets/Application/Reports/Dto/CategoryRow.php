<?php

namespace App\Modules\Wallets\Application\Reports\Dto;

/**
 * The sum of one category's legs in a range: expense legs positive, income
 * legs negative. `systemKey` is null for a person's category.
 */
final readonly class CategoryRow
{
    public function __construct(
        public string $kind,
        public string $categoryId,
        public ?string $systemKey,
        public int $amountMinor,
    ) {}
}
