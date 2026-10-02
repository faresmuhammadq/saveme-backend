<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Domain\Recurring\OccurrencePlan;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\OccurrenceStatus;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use DateTimeZone;

/**
 * Brings a rule's pending occurrences in line with the rule after an edit
 * (spec 014, FR-006, FR-007, research R-9): a pending, unedited occurrence
 * takes the values the rule now gives it (version + 1 and its own change_seq,
 * only when something differs); one the schedule no longer gives is
 * tombstoned, and so is an edited one that lies beyond a shortened end; an
 * edited one keeps its values. Confirmed and skipped occurrences are history
 * and are never touched. Ending the rule tombstones its pending occurrences
 * dated after the rule's today.
 *
 * Must run inside the write's unit of work with the rule's wallet and row
 * locked; it locks the occurrence rows, ascending id.
 */
final class OccurrenceReconciler
{
    public function __construct(
        private readonly OccurrenceRepository $occurrences,
        private readonly RecurringStamps $stamps,
    ) {}

    /**
     * @return int the occurrences changed
     */
    public function reconcile(RecurringRuleRecord $rule, string $today): int
    {
        $schedule = $rule->schedule();
        $tombstones = [];
        $changed = 0;

        foreach ($this->occurrences->lockLiveForRule($rule->id) as $occurrence) {
            if ($occurrence->status !== OccurrenceStatus::Scheduled) {
                continue;
            }

            if ($rule->status === RuleStatus::Ended) {
                if ($occurrence->date > $today) {
                    $tombstones[] = $occurrence->id;
                }

                continue;
            }

            $ordinal = $schedule->ordinalOf($occurrence->scheduledDate);
            $onSchedule = $occurrence->isPayout ? $ordinal !== null && $ordinal === $rule->payoutRound : $ordinal !== null;

            if (! $onSchedule || $ordinal === null) {
                if (! $occurrence->edited || $schedule->isAfterEnd($occurrence->scheduledDate)) {
                    $tombstones[] = $occurrence->id;
                }

                continue;
            }

            $columns = self::changes($occurrence, OccurrencePlan::forRound($rule, $ordinal, $occurrence->scheduledDate, $occurrence->isPayout));

            if ($columns !== []) {
                $this->occurrences->updateIfVersion($occurrence->id, $occurrence->version, $columns, $this->stamps->next($rule->walletId));
                $changed++;
            }
        }

        if ($tombstones !== []) {
            sort($tombstones, SORT_STRING);
            $first = $this->stamps->block($rule->walletId, count($tombstones));
            $this->occurrences->updateMany($tombstones, ['deleted_at' => now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP')], $first);
            $changed += count($tombstones);
        }

        return $changed;
    }

    /**
     * The columns that differ from what the rule gives; an edited occurrence
     * keeps its values and only follows the round number.
     *
     * @return array<string, scalar|null>
     */
    private static function changes(OccurrenceRecord $occurrence, OccurrencePlan $plan): array
    {
        $columns = [];

        if ($occurrence->ordinal !== $plan->ordinal) {
            $columns['ordinal'] = $plan->ordinal;
        }

        if ($occurrence->edited) {
            return $columns;
        }

        foreach ([
            'amount_minor' => [$occurrence->amountMinor, $plan->amountMinor],
            'category_id' => [$occurrence->categoryId, $plan->categoryId],
            'description' => [$occurrence->description, $plan->description],
            'memo' => [$occurrence->memo, $plan->memo],
            'counterparty' => [$occurrence->counterparty, $plan->counterparty],
        ] as $column => [$stored, $given]) {
            if ($stored !== $given) {
                $columns[$column] = $given;
            }
        }

        return $columns;
    }
}
