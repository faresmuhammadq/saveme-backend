<?php

namespace App\Modules\Budgeting\Application\Listeners;

use App\Modules\Budgeting\Application\Overruns\DetectBudgetOverruns;
use App\Modules\Wallets\Domain\Events\TransactionPosted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An expense began to count (recorded, amended or restored): the author's
 * matching budgets are checked for a first overrun (spec 023, FR-026).
 * Queued and idempotent: the overrun record is unique per budget, period and
 * threshold, so a redelivered event records and announces nothing more.
 */
final class EvaluateBudgetsAfterPosting implements ShouldQueue
{
    public function __construct(private readonly DetectBudgetOverruns $overruns) {}

    public function handle(TransactionPosted $event): void
    {
        if ($event->kind !== 'expense') {
            return;
        }

        $this->overruns->forTransaction($event->transactionId);
    }
}
