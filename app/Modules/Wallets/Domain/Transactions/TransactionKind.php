<?php

namespace App\Modules\Wallets\Domain\Transactions;

/**
 * What a transaction is. `income` and `expense` move money between a wallet
 * and a category; `transfer` moves it between two wallets and is excluded
 * from every spending and income total. Never changes after creation.
 */
enum TransactionKind: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
}
