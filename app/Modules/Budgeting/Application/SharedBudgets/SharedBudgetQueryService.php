<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Application\SharedBudgets\Dto\SharedBudgetPage;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;

/**
 * Reads of a Shared Budget, bound to the caller's membership (ADR-0027 rule 2).
 */
final class SharedBudgetQueryService
{
    public function __construct(
        private readonly SharedBudgetRepository $budgets,
        private readonly SharedBudgetAuthorizer $authorizer,
    ) {}

    /**
     * @param  array{value: string, id: string}|null  $after
     */
    public function list(string $userId, bool $descending, ?array $after, int $limit): SharedBudgetPage
    {
        $rows = $this->budgets->listForMember($userId, $descending, $after, $limit);
        $items = array_slice($rows, 0, $limit);
        $last = $items === [] ? null : $items[array_key_last($items)];

        return new SharedBudgetPage($items, count($rows) > $limit && $last !== null ? ['value' => $this->budgets->sortValue($last), 'id' => $last->id] : null);
    }

    public function find(string $userId, string $budgetId): SharedBudgetRecord
    {
        $this->authorizer->member($userId, $budgetId);

        return $this->budgets->find($budgetId) ?? throw new SharedBudgetNotFound;
    }

    /**
     * @return list<SharedBudgetMemberRecord>
     */
    public function members(string $userId, string $budgetId): array
    {
        $this->authorizer->member($userId, $budgetId);

        return $this->budgets->activeMembers($budgetId);
    }

    /**
     * @return list<SharedBudgetLineRecord>
     */
    public function lines(string $userId, string $budgetId): array
    {
        $this->authorizer->member($userId, $budgetId);

        return $this->budgets->liveLines($budgetId);
    }
}
