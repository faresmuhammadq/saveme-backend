<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Domain\Recurring\OccurrencePlan;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Recurring\RuleDates;
use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use App\Modules\Wallets\Domain\Recurring\RuleToday;

/**
 * Keeps a rule's occurrences materialised through its horizon (spec 014,
 * FR-011, FR-012, plan design note 2): every scheduled date from the later of
 * the start and the creation day minus the backfill, through the rule's
 * today plus the horizon and at least the next date after today, at most
 * `max_per_pass` new rows a pass. Idempotent by the occurrence key: a key the
 * rule already holds is skipped, and an `INSERT … ON CONFLICT DO NOTHING`
 * backs that up. A tombstoned, still scheduled occurrence whose date the
 * schedule gives again (a schedule corrected back before anything was
 * resolved) is revived. A gam'eya round on the payout round carries one more
 * occurrence, the payout. A no-op for a paused, ended or deleted rule.
 *
 * Must run inside the write's unit of work, with the rule and its wallet
 * locked.
 */
final class Materialiser
{
    public function __construct(
        private readonly OccurrenceRepository $occurrences,
        private readonly RecurringStamps $stamps,
    ) {}

    /**
     * @return int the occurrences created or revived
     */
    public function extend(RecurringRuleRecord $rule, string $today): int
    {
        ['plans' => $plans, 'revived' => $revived] = $this->pending($rule, $today);
        $count = count($plans) + count($revived);

        if ($count === 0) {
            return 0;
        }

        $first = $this->stamps->block($rule->walletId, $count);
        $this->occurrences->insertMany($rule->id, $rule->walletId, $plans, $first);

        foreach ($revived as $index => $entry) {
            $this->occurrences->revive($entry['id'], $entry['plan'], $first + count($plans) + $index);
        }

        return $count;
    }

    /**
     * Whether the rule has occurrences to materialise now: an unlocked,
     * read-only look the job takes before it opens a unit of work, so a rule
     * with nothing to do costs a query rather than a transaction. The locked
     * pass decides again, idempotently.
     */
    public function isBehind(RecurringRuleRecord $rule, string $today): bool
    {
        $pending = $this->pending($rule, $today);

        return $pending['plans'] !== [] || $pending['revived'] !== [];
    }

    /**
     * What the rule still lacks through its horizon: the plans to insert and
     * the tombstoned, still scheduled occurrences to revive, at most
     * `max_per_pass` together.
     *
     * @return array{plans: list<OccurrencePlan>, revived: list<array{id: string, plan: OccurrencePlan}>}
     */
    private function pending(RecurringRuleRecord $rule, string $today): array
    {
        $none = ['plans' => [], 'revived' => []];

        if ($rule->status !== RuleStatus::Active || $rule->isTombstone()) {
            return $none;
        }

        $schedule = $rule->schedule();
        $createdDay = RuleToday::of($rule->timezone, $rule->createdAt);

        $from = max($rule->startDate, RuleDates::addDays($createdDay, -(int) config('wallets.recurring.backfill_days')));
        $to = RuleDates::addDays($today, (int) config('wallets.recurring.horizon_days'));
        $next = $schedule->nextAfter($today);

        if ($next !== null && $next['date'] > $to) {
            $to = $next['date'];
        }

        if ($from > $to) {
            return $none;
        }

        $max = (int) config('wallets.recurring.max_per_pass');
        $existing = $this->occurrences->existingKeys($rule->id, $from, $to);

        $plans = [];
        $revived = [];

        foreach ($schedule->between($from, $to, $max + count($existing)) as $round) {
            $payoutRound = $rule->payoutRound !== null && $round['ordinal'] === $rule->payoutRound;

            foreach ($payoutRound ? [false, true] : [false] as $isPayout) {
                $key = $round['date'].'|'.($isPayout ? '1' : '0');

                if (! isset($existing[$key])) {
                    $plans[] = OccurrencePlan::forRound($rule, $round['ordinal'], $round['date'], $isPayout);
                } elseif ($existing[$key]['deleted'] && $existing[$key]['status'] === 'scheduled') {
                    $revived[] = ['id' => $existing[$key]['id'], 'plan' => OccurrencePlan::forRound($rule, $round['ordinal'], $round['date'], $isPayout)];
                }
            }

            if (count($plans) + count($revived) >= $max) {
                break;
            }
        }

        return ['plans' => $plans, 'revived' => $revived];
    }
}
