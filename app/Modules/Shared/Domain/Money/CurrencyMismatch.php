<?php

namespace App\Modules\Shared\Domain\Money;

use LogicException;

/**
 * Arithmetic was attempted between two currencies (P-03 rule 1, P-04 rule 4):
 * cross-currency amounts are never combined, converted or rounded in MVP.
 */
final class CurrencyMismatch extends LogicException
{
    public function __construct(string $left, string $right)
    {
        parent::__construct("Can not combine [{$left}] with [{$right}].");
    }
}
