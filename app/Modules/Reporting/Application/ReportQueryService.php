<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Budgeting\Application\BudgetPeriodQuery;
use App\Modules\Budgeting\Application\Dto\BudgetPeriodSpan;
use App\Modules\Identity\Application\Profiles\ReportingPreferences;
use App\Modules\Reporting\Application\Dto\CategoryReport;
use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\Dto\SummaryReport;
use App\Modules\Reporting\Application\Dto\TrendReport;
use App\Modules\Reporting\Domain\CategoryBreakdown;
use App\Modules\Reporting\Domain\CategoryTotal;
use App\Modules\Reporting\Domain\DailyTotal;
use App\Modules\Reporting\Domain\LegTotal;
use App\Modules\Reporting\Domain\PlannedFigures;
use App\Modules\Reporting\Domain\PlannedSummary;
use App\Modules\Reporting\Domain\ReportRange;
use App\Modules\Reporting\Domain\ScheduledTotal;
use App\Modules\Reporting\Domain\SummaryFigures;
use App\Modules\Reporting\Domain\TrendBucket;
use App\Modules\Reporting\Domain\TrendSeries;
use App\Modules\Shared\Domain\Money\Money;
use App\Modules\Wallets\Application\Reports\Dto\CategoryRow;
use App\Modules\Wallets\Application\Reports\Dto\DailyRow;
use App\Modules\Wallets\Application\Reports\Dto\LegRow;
use App\Modules\Wallets\Application\Reports\Dto\ScheduledRow;
use App\Modules\Wallets\Application\Reports\LedgerReportQuery;

/**
 * The six Reports (spec 019): a summary, a by-category breakdown and a trend,
 * each for one wallet or across the caller's wallets in one currency.
 * Read-only and computed on every call from the ledger (ADR-0022): the
 * ledger sums come from Wallets, the Budget Periods from Budgeting, the
 * default currency from Identity, and the arithmetic from Reporting's Domain.
 * Every report reads one repeatable-read snapshot, so its balance identity
 * cannot straddle a concurrent commit.
 */
final class ReportQueryService
{
    public function __construct(
        private readonly LedgerReportQuery $ledger,
        private readonly BudgetPeriodQuery $periods,
        private readonly ReportingPreferences $preferences,
    ) {}

    /**
     * Refuses with not-found unless the caller may read a live wallet (matrix row W-01).
     */
    public function assertWalletReadable(string $userId, string $walletId): void
    {
        $this->ledger->readableWallet($userId, $walletId);
    }

    public function walletSummary(string $userId, string $walletId, ReportRequest $request): SummaryReport
    {
        $range = $this->rangeOf($userId, $request);

        return $this->ledger->snapshot(fn (): SummaryReport => $this->summaryOver(ReportScope::ofWallet($this->ledger->readableWallet($userId, $walletId)), $range, $request));
    }

    public function walletByCategory(string $userId, string $walletId, ReportRequest $request): CategoryReport
    {
        $range = $this->rangeOf($userId, $request);

        return $this->ledger->snapshot(fn (): CategoryReport => $this->categoriesOver(ReportScope::ofWallet($this->ledger->readableWallet($userId, $walletId)), $range, $request));
    }

    public function walletTrend(string $userId, string $walletId, ReportRequest $request): TrendReport
    {
        $buckets = $this->bucketsOf($userId, $request);

        return $this->ledger->snapshot(fn (): TrendReport => $this->trendOver(ReportScope::ofWallet($this->ledger->readableWallet($userId, $walletId)), $buckets, $request));
    }

    public function summary(string $userId, ReportRequest $request): SummaryReport
    {
        $range = $this->rangeOf($userId, $request);

        return $this->ledger->snapshot(fn (): SummaryReport => $this->summaryOver($this->scopeAcross($userId, $request), $range, $request));
    }

    public function byCategory(string $userId, ReportRequest $request): CategoryReport
    {
        $range = $this->rangeOf($userId, $request);

        return $this->ledger->snapshot(fn (): CategoryReport => $this->categoriesOver($this->scopeAcross($userId, $request), $range, $request));
    }

    public function trend(string $userId, ReportRequest $request): TrendReport
    {
        $buckets = $this->bucketsOf($userId, $request);

        return $this->ledger->snapshot(fn (): TrendReport => $this->trendOver($this->scopeAcross($userId, $request), $buckets, $request));
    }

    private function summaryOver(ReportScope $scope, ReportRange $range, ReportRequest $request): SummaryReport
    {
        $ids = $scope->coveredIds();
        $currency = $scope->currency;

        if ($ids === []) {
            $zero = Money::of(0, $currency);

            return new SummaryReport($scope->walletId, $range, $currency, SummaryFigures::empty($currency), $request->includePlanned ? new PlannedSummary($zero, $zero, $zero, $zero) : null, $scope->excluded);
        }

        $legs = array_map(fn (LegRow $row): LegTotal => new LegTotal($row->walletId, $row->kind, $row->otherWalletId, Money::of($row->amountMinor, $currency)), $this->ledger->walletLegTotals($ids, $range->from, $range->to));
        $start = $scope->openingTotal();

        foreach ($this->ledger->balancesBefore($ids, $range->from) as $sum) {
            $start = $start->plus(Money::of($sum, $currency));
        }

        $planned = $request->includePlanned ? PlannedFigures::summary($this->scheduled($ids, $currency, $range->from, $range->to), $ids, $currency) : null;

        return new SummaryReport($scope->walletId, $range, $currency, SummaryFigures::from($legs, $start, $ids), $planned, $scope->excluded);
    }

    private function categoriesOver(ReportScope $scope, ReportRange $range, ReportRequest $request): CategoryReport
    {
        $ids = $scope->coveredIds();
        $currency = $scope->currency;

        if ($ids === []) {
            return new CategoryReport($scope->walletId, $range, $currency, CategoryBreakdown::from([], $request->includePlanned ? [] : null, $currency), $scope->excluded);
        }

        $actual = array_map(
            fn (CategoryRow $row): CategoryTotal => new CategoryTotal(
                $row->kind,
                $row->categoryId,
                $row->systemKey,
                $row->kind === LegTotal::INCOME ? Money::of($row->amountMinor, $currency)->negate() : Money::of($row->amountMinor, $currency),
            ),
            $this->ledger->categoryTotals($ids, $range->from, $range->to),
        );
        $planned = $request->includePlanned ? PlannedFigures::categories($this->scheduled($ids, $currency, $range->from, $range->to)) : null;

        return new CategoryReport($scope->walletId, $range, $currency, CategoryBreakdown::from($actual, $planned, $currency), $scope->excluded);
    }

    /**
     * @param  non-empty-list<TrendBucket>  $buckets  oldest first
     */
    private function trendOver(ReportScope $scope, array $buckets, ReportRequest $request): TrendReport
    {
        $ids = $scope->coveredIds();
        $currency = $scope->currency;

        if ($ids === []) {
            return new TrendReport($scope->walletId, $currency, TrendSeries::bucket($buckets, [], $request->includePlanned ? [] : null, $currency), $scope->excluded);
        }

        $from = min(array_map(fn (TrendBucket $bucket): string => $bucket->from, $buckets));
        $to = max(array_map(fn (TrendBucket $bucket): string => $bucket->to, $buckets));
        $daily = array_map(fn (DailyRow $row): DailyTotal => new DailyTotal($row->date, $row->kind, Money::of($row->amountMinor, $currency)), $this->ledger->dailyTotals($ids, $from, $to));
        $planned = $request->includePlanned ? PlannedFigures::daily($this->scheduled($ids, $currency, $from, $to)) : null;

        return new TrendReport($scope->walletId, $currency, TrendSeries::bucket($buckets, $daily, $planned, $currency), $scope->excluded);
    }

    /**
     * @param  list<string>  $ids
     * @return list<ScheduledTotal>
     */
    private function scheduled(array $ids, string $currency, string $from, string $to): array
    {
        return array_map(
            fn (ScheduledRow $row): ScheduledTotal => new ScheduledTotal($row->date, $row->kind, $row->categoryId, $row->systemKey, $row->walletId, $row->counterWalletId, Money::of($row->amountMinor, $currency)),
            $this->ledger->scheduledTotals($ids, $currency, $from, $to),
        );
    }

    private function scopeAcross(string $userId, ReportRequest $request): ReportScope
    {
        return ReportScope::across($this->ledger->walletsOf($userId), $request->currency ?? $this->preferences->defaultCurrencyOf($userId));
    }

    /**
     * The explicit range, else the caller's Budget Period containing the date (today by default).
     */
    private function rangeOf(string $userId, ReportRequest $request): ReportRange
    {
        if ($request->range !== null) {
            return $request->range;
        }

        $span = $this->periods->periodContaining($userId, $request->date ?? $this->periods->todayOf($userId));

        return ReportRange::ofBudgetPeriod($span->from, $span->to);
    }

    /**
     * @return non-empty-list<TrendBucket> `months` is at least 1
     */
    private function bucketsOf(string $userId, ReportRequest $request): array
    {
        /** @var non-empty-list<TrendBucket> $buckets */
        $buckets = array_map(
            fn (BudgetPeriodSpan $span): TrendBucket => new TrendBucket($span->from, $span->to),
            $this->periods->periodsEndingWith($userId, $request->date ?? $this->periods->todayOf($userId), $request->months),
        );

        return $buckets;
    }
}
