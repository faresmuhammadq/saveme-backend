<?php

namespace App\Modules\Wallets\Domain\Categories;

/**
 * Which side of the ledger a category stands on (ADR-0012). Never changes
 * after creation.
 */
enum CategoryKind: string
{
    case Income = 'income';
    case Expense = 'expense';
}
