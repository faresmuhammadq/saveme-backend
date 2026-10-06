<?php

namespace App\Modules\Budgeting\Application\Overruns;

use App\Modules\Budgeting\Application\BudgetPeriodQuery;
use App\Modules\Budgeting\Application\BudgetQueryService;
use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Domain\Events\BudgetExceeded;
use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Budgeting\Domain\Ports\OverrunRepository;
use App\Modules\Wallets\Application\TransactionQueryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Detects the first time a budget's spending goes strictly above its limit in
 * the current Budget Period (spec 023, FR-026, ADR-0025). Progress stays
 * computed on read (ADR-0019, through BudgetQueryService); only the fact that
 * the period crossed its limit is stored, once per budget, period and
 * threshold, and `BudgetExceeded` is published from that insert. Spending
 * exactly equal to the limit is not over it. An archived budget never alerts;
 * an expense dated outside the current period never alerts for a past one.
 */
final class DetectBudgetOverruns
{
    private const THRESHOLD = 100;

    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly OverrunRepository $overruns,
        private readonly BudgetQueryService $progress,
        private readonly BudgetPeriodQuery $periods,
        private readonly TransactionQueryService $transactions,
    ) {}

    /**
     * Evaluates the author's budgets that the transaction counts toward.
     * Safe to repeat: the unique record is the arbiter.
     *
     * @return int overruns recorded by this call
     */
    public function forTransaction(string $transactionId): int
    {
        $transaction = $this->transactions->authorAndCurrencyOf($transactionId);

        if ($transaction === null || $transaction['kind'] !== 'expense') {
            return 0;
        }

        $authorId = $transaction['author_id'];
        $today = $this->periods->todayOf($authorId);

        // Only the period containing today can alert: an edit to the past never does (A-10).
        if (! $this->periods->containing($authorId, $today)->contains($transaction['date'])) {
            return 0;
        }

        $recorded = 0;

        foreach ($this->budgets->activeMatching($authorId, $transaction['currency'], $transaction['category_id']) as $budget) {
            $recorded += $this->evaluate($budget) ? 1 : 0;
        }

        return $recorded;
    }

    /**
     * Evaluates one budget after its limit changed.
     */
    public function forBudget(string $ownerId, string $budgetId): bool
    {
        $budget = $this->budgets->findOwned($budgetId, $ownerId);

        if ($budget === null || $budget->isTombstoned() || $budget->isArchived()) {
            return false;
        }

        return $this->evaluate($budget);
    }

    private function evaluate(BudgetRecord $budget): bool
    {
        $progress = $this->progress->progressOf($budget->userId, $budget->id, null);

        if (! $progress->overLimit) {
            return false;
        }

        return DB::transaction(function () use ($budget, $progress): bool {
            $at = now()->toDateTimeImmutable();

            if (! $this->overruns->recordOnce($budget->id, $budget->userId, $progress->period->start, self::THRESHOLD, $at)) {
                return false;
            }

            event(new BudgetExceeded(
                (string) Str::uuid7(),
                $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                $budget->id,
                $budget->userId,
                $progress->period->start,
                self::THRESHOLD,
            ));

            return true;
        });
    }
}
