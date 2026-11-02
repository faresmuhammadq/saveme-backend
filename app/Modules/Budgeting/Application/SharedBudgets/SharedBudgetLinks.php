<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\BudgetCategoryCheck;
use App\Modules\Wallets\Application\Transactions\Dto\LinkedExpense;
use App\Modules\Wallets\Application\Transactions\SharedBudgetLink;

/**
 * Budgeting's side of the transaction link (ADR-0027 rules 4 to 8): an entry
 * is written in the same unit of work as the ledger write, stamped from the
 * budget's counter, and carries nothing of the wallet.
 */
final class SharedBudgetLinks implements SharedBudgetLink
{
    public function __construct(
        private readonly SharedBudgetRepository $budgets,
        private readonly BudgetCategoryCheck $categories,
    ) {}

    public function lock(array $budgetIds): void
    {
        foreach ($budgetIds as $budgetId) {
            $this->budgets->findForUpdate($budgetId);
        }
    }

    public function refusal(string $authorId, string $budgetId, string $kind, string $currency, ?string $categoryId): ?ErrorCode
    {
        $budget = $this->budgets->find($budgetId);
        $member = $this->budgets->activeMember($budgetId, $authorId);

        if ($budget === null || $budget->isTombstoned() || $budget->isArchived() || $member === null || $member->readOnly || $kind !== 'expense') {
            return ErrorCode::InvalidEnumValue;
        }

        if ($currency !== $budget->currency) {
            return ErrorCode::CurrencyMismatch;
        }

        if ($categoryId === null || ! $this->allowsCategory($budgetId, $categoryId)) {
            return ErrorCode::InvalidEnumValue;
        }

        return null;
    }

    public function allowsCategory(string $budgetId, string $categoryId): bool
    {
        return $this->budgets->lineNames($budgetId, $categoryId) || $this->categories->isPlatform($categoryId);
    }

    public function lineNames(string $budgetId, string $categoryId): bool
    {
        return $this->budgets->lineNames($budgetId, $categoryId);
    }

    public function follow(string $budgetId, LinkedExpense $expense): void
    {
        $entry = $this->budgets->entryFor($budgetId, $expense->transactionId);
        $values = [
            'created_by' => $expense->authorId,
            'category_id' => $expense->categoryId,
            'date' => $expense->date,
            'amount_minor' => $expense->amountMinor,
            'description' => $expense->description,
        ];

        if ($entry === null) {
            $this->budgets->insertEntry($budgetId, $expense->transactionId, $values, $this->budgets->nextSeq($budgetId));
            $this->budgets->nextSeq($budgetId);

            return;
        }

        if ($entry->isDetached()) {
            return;
        }

        $same = ! $entry->isTombstoned()
            && $entry->categoryId === $values['category_id']
            && $entry->date === $values['date']
            && $entry->amountMinor === $values['amount_minor']
            && $entry->description === $values['description'];

        if ($same) {
            return;
        }

        $this->budgets->updateEntry($entry->id, [...$values, 'deleted_at' => null], $this->budgets->nextSeq($budgetId));
        $this->budgets->nextSeq($budgetId);
    }

    public function unlink(string $budgetId, string $transactionId): void
    {
        $entry = $this->budgets->entryFor($budgetId, $transactionId);

        if ($entry === null || $entry->isDetached() || $entry->isTombstoned()) {
            return;
        }

        $this->budgets->updateEntry($entry->id, ['deleted_at' => $this->budgets->now()->format('Y-m-d H:i:s.uP')], $this->budgets->nextSeq($budgetId));
        $this->budgets->nextSeq($budgetId);
    }

    public function detachForTransactions(array $transactionIds): void
    {
        if ($transactionIds === []) {
            return;
        }

        $now = $this->budgets->now()->format('Y-m-d H:i:s.uP');
        $locked = [];

        foreach ($this->budgets->followingEntriesForTransactions($transactionIds) as $entry) {
            if (! in_array($entry->sharedBudgetId, $locked, true)) {
                $this->budgets->findForUpdate($entry->sharedBudgetId);
                $locked[] = $entry->sharedBudgetId;
            }

            $this->budgets->updateEntry($entry->id, ['detached_at' => $now], $this->budgets->nextSeq($entry->sharedBudgetId));
        }

        foreach ($locked as $budgetId) {
            $this->budgets->nextSeq($budgetId);
        }
    }
}
