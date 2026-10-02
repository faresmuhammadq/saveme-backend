<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Application\Dto\BudgetPage;
use App\Modules\Budgeting\Application\Dto\ProgressPage;
use App\Modules\Budgeting\Domain\BudgetPeriod;
use App\Modules\Budgeting\Domain\BudgetPeriods;
use App\Modules\Budgeting\Domain\BudgetProgress;
use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Budgeting\Domain\Refusals\BudgetNotFound;
use App\Modules\Budgeting\Domain\Refusals\ProgressCursorMismatch;
use App\Modules\Budgeting\Domain\SpendingLine;
use App\Modules\Budgeting\Domain\StartDayEntry;
use App\Modules\Identity\Application\Profiles\BudgetStartDayQuery;
use App\Modules\Wallets\Application\Transactions\Dto\SpendingTotal;
use App\Modules\Wallets\Application\Transactions\SpendingQueryService;
use DateTimeZone;

/**
 * Reads of a person's budgets and their progress (spec 015, FR-013 to
 * FR-017). Progress is computed on every read from one grouped spending
 * query (research R-1); nothing is stored or cached. Every read is bound to
 * the caller: another person's budget is not found.
 */
final class BudgetQueryService
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly SpendingQueryService $spending,
        private readonly BudgetStartDayQuery $startDays,
    ) {}

    /**
     * The caller's live budgets, archived included, by `created_at`.
     *
     * @param  array{value: string, id: string}|null  $after
     */
    public function list(string $userId, bool $descending, ?array $after, int $limit): BudgetPage
    {
        $rows = $this->budgets->listLive($userId, false, $descending, $after, $limit);
        $items = array_slice($rows, 0, $limit);
        $last = $items === [] ? null : $items[array_key_last($items)];

        return new BudgetPage($items, count($rows) > $limit && $last !== null ? ['value' => $this->budgets->sortValue($last), 'id' => $last->id] : null);
    }

    /**
     * The caller's budget, tombstone included.
     */
    public function find(string $userId, string $budgetId): BudgetRecord
    {
        return $this->budgets->findOwned($budgetId, $userId) ?? throw new BudgetNotFound;
    }

    /**
     * Progress of every live, active budget for one Budget Period. The first
     * page resolves the period from `$date` or today; a later page carries
     * its period in `$after`.
     *
     * @param  ?string  $date  YYYY-MM-DD
     * @param  array{value: string, id: string, period_start: string}|null  $after
     */
    public function progressList(string $userId, ?string $date, ?array $after, int $limit): ProgressPage
    {
        $today = $this->todayOf($userId);
        $entries = $this->entriesOf($userId);
        $fallback = $this->startDays->currentDayOf($userId);

        if ($after === null) {
            $period = BudgetPeriods::containing($date ?? $today, $entries, $fallback);
        } else {
            $period = BudgetPeriods::containing($after['period_start'], $entries, $fallback);

            if ($period->start !== $after['period_start'] || ($date !== null && ! $period->contains($date))) {
                throw new ProgressCursorMismatch;
            }
        }

        $rows = $this->budgets->listLive($userId, true, false, $after === null ? null : ['value' => $after['value'], 'id' => $after['id']], $limit);
        $items = array_slice($rows, 0, $limit);

        if ($items === []) {
            return new ProgressPage([], null);
        }

        $lines = $this->linesFor($userId, $period);
        $last = $items[array_key_last($items)];

        return new ProgressPage(
            array_map(fn (BudgetRecord $budget): BudgetProgress => BudgetProgress::of($budget, $period, $lines, $today), $items),
            count($rows) > $limit ? ['value' => $this->budgets->sortValue($last), 'id' => $last->id, 'period_start' => $period->start] : null,
        );
    }

    /**
     * Progress of one live budget, archived included.
     *
     * @param  ?string  $date  YYYY-MM-DD
     */
    public function progressOf(string $userId, string $budgetId, ?string $date): BudgetProgress
    {
        $budget = $this->find($userId, $budgetId);

        if ($budget->isTombstoned()) {
            throw new BudgetNotFound;
        }

        $today = $this->todayOf($userId);
        $period = BudgetPeriods::containing($date ?? $today, $this->entriesOf($userId), $this->startDays->currentDayOf($userId));

        return BudgetProgress::of($budget, $period, $this->linesFor($userId, $period), $today);
    }

    /**
     * Today in the owner's profile timezone (FR-009).
     */
    private function todayOf(string $userId): string
    {
        return now()->toDateTimeImmutable()->setTimezone(new DateTimeZone($this->startDays->timezoneOf($userId)))->format('Y-m-d');
    }

    /**
     * @return list<StartDayEntry>
     */
    private function entriesOf(string $userId): array
    {
        return array_map(
            fn (array $entry): StartDayEntry => new StartDayEntry($entry['start_day'], $entry['applies_from']),
            $this->startDays->entriesOf($userId),
        );
    }

    /**
     * @return list<SpendingLine>
     */
    private function linesFor(string $userId, BudgetPeriod $period): array
    {
        return array_map(
            fn (SpendingTotal $total): SpendingLine => new SpendingLine($total->categoryId, $total->currency, $total->amountMinor),
            $this->spending->expensesRecordedBy($userId, $period->start, $period->end),
        );
    }
}
