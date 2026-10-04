<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The sum of one category's figures of one kind, positive for both expense
 * and income (the ledger's negative income legs are negated on the way in).
 * `systemKey` is null for a person's category.
 */
final readonly class CategoryTotal
{
    public function __construct(
        public string $kind,
        public string $categoryId,
        public ?string $systemKey,
        public Money $amount,
    ) {}
}
