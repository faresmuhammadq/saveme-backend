<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Transactions\Dto\LinkedExpense;

/**
 * Wallets' view of Budgeting's Shared Budgets (ADR-0027 rules 4 to 8),
 * implemented by Budgeting and bound in its provider, so Wallets never reads
 * another module's tables (P-02).
 *
 * LOCK ORDER (ADR-0027 rule 10): categories, wallets ascending, then the
 * budgets this port locks, ascending, then the transaction row.
 */
interface SharedBudgetLink
{
    /**
     * Locks the budget rows, in the order given (the caller sorts ascending).
     * Runs inside the caller's transaction.
     *
     * @param  list<string>  $budgetIds
     */
    public function lock(array $budgetIds): void;

    /**
     * Why the author may not link an expense to the budget, or null: unknown,
     * foreign, archived or deleted budget, a read-only member, a category the
     * members cannot render, or a non-expense answer INVALID_ENUM_VALUE; a
     * different currency answers CURRENCY_MISMATCH.
     */
    public function refusal(string $authorId, string $budgetId, string $kind, string $currency, ?string $categoryId): ?ErrorCode;

    /**
     * Whether a transaction linked to the budget may name the category: one a
     * line names, or a platform category every member already holds.
     */
    public function allowsCategory(string $budgetId, string $categoryId): bool;

    /**
     * Whether one of the budget's lines, live or tombstoned, names the category.
     */
    public function lineNames(string $budgetId, string $categoryId): bool;

    /**
     * Writes or refreshes the transaction's entry, reviving a tombstoned one.
     * A detached entry no longer follows its transaction and is left alone.
     */
    public function follow(string $budgetId, LinkedExpense $expense): void;

    /**
     * Tombstones the transaction's entry. A detached entry is left alone.
     */
    public function unlink(string $budgetId, string $transactionId): void;

    /**
     * Detaches every following entry of the transactions (a wallet's deletion),
     * keeping amount, date and category.
     *
     * @param  list<string>  $transactionIds
     */
    public function detachForTransactions(array $transactionIds): void;
}
