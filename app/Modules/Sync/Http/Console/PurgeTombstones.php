<?php

namespace App\Modules\Sync\Http\Console;

use App\Modules\Sync\Application\TombstonePurgeRun;
use Illuminate\Console\Command;

/**
 * Removes syncable tombstones past the retention and raises purge horizons
 * (ADR-0008 rule 12, spec 013).
 */
final class PurgeTombstones extends Command
{
    protected $signature = 'sync:purge-tombstones';

    protected $description = 'Remove syncable tombstones older than the retention and raise the purge horizons';

    public function handle(TombstonePurgeRun $run): int
    {
        $removed = $run->handle();

        $this->info('Purged '.array_sum($removed).' tombstones.');

        return self::SUCCESS;
    }
}
