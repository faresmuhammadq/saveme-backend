<?php

namespace App\Modules\Wallets\Http\Console;

use App\Modules\Wallets\Application\Recurring\RecurringPass;
use Illuminate\Console\Command;

/**
 * Keeps every active recurring rule's occurrences materialised through its
 * horizon and confirms the due occurrences of automatic rules (spec 014,
 * FR-020). Idempotent: a rerun, or one overlapping a materialise request,
 * changes nothing more.
 */
final class MaterialiseOccurrences extends Command
{
    protected $signature = 'wallets:materialise-occurrences';

    protected $description = 'Materialise recurring occurrences through their horizon and confirm the due ones of automatic rules';

    public function handle(RecurringPass $pass): int
    {
        $totals = $pass->run();

        $this->info("Processed {$totals['rules']} rules: {$totals['materialised']} occurrences materialised, {$totals['confirmed']} confirmed, {$totals['refused']} refused, {$totals['failed']} failed.");

        return self::SUCCESS;
    }
}
