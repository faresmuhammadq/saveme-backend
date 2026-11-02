<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Budgeting's published way for another module to write a record of a Shared
 * Budget's scope (ADR-0027 rule 9), as Wallets' WalletScopeWrites does for a
 * wallet: one unit of work under the budget row lock, each record taking its
 * stamp from `nextChangeSeq()`, and the budget taking the last. LOCK ORDER
 * (rule 10): the budget row first, then user rows ascending, then records.
 */
final class SharedBudgetScopeWrites
{
    public function __construct(private readonly SharedBudgetRepository $budgets) {}

    /**
     * @template T
     *
     * @param  Closure(SharedBudgetRecord, Closure(): int): T  $work  the locked budget and the stamp source
     * @return T
     *
     * @throws SharedBudgetNotFound
     * @throws UniqueConstraintViolationException when a record written inside lost an identifier race
     */
    public function within(string $budgetId, Closure $work): mixed
    {
        return DB::transaction(function () use ($budgetId, $work): mixed {
            $budget = $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;

            $result = $work($budget, fn (): int => $this->budgets->nextSeq($budgetId));

            $this->budgets->nextSeq($budgetId);

            return $result;
        });
    }
}
