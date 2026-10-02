<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The hourly job (spec 014, FR-020, research R-12): pages the live, active
 * rules by id and gives each its own unit of work and the same catch-up the
 * materialise request runs, so one rule's failure never blocks another. A
 * failure is logged with the rule id and the exception class only; the run
 * ends with one summary line of counts. No amount, description, memo or
 * counterparty reaches a log line (P-15).
 */
final class RecurringPass
{
    private const PAGE = 500;

    public function __construct(
        private readonly RecurringRuleRepository $rules,
        private readonly RecurringRuleService $service,
    ) {}

    /**
     * @return array{rules: int, materialised: int, confirmed: int, refused: int, failed: int}
     */
    public function run(): array
    {
        $totals = ['rules' => 0, 'materialised' => 0, 'confirmed' => 0, 'refused' => 0, 'failed' => 0];
        $afterId = '';

        while (($page = $this->rules->nextForJob($afterId, self::PAGE)) !== []) {
            $afterId = $page[array_key_last($page)]->id;

            foreach ($page as $rule) {
                $totals['rules']++;

                try {
                    $result = $this->service->catchUp($rule->id, $rule);
                } catch (Throwable $exception) {
                    $totals['failed']++;
                    Log::warning('Recurring rule pass failed', ['rule_id' => $rule->id, 'exception' => $exception::class]);

                    continue;
                }

                $totals['materialised'] += $result['materialised'];
                $totals['confirmed'] += $result['confirmed'];
                $totals['refused'] += $result['refused'];
            }
        }

        Log::info('Recurring rules materialised', $totals);

        return $totals;
    }
}
