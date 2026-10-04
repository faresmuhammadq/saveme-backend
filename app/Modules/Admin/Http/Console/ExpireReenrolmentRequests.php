<?php

namespace App\Modules\Admin\Http\Console;

use App\Modules\Admin\Application\Reenrolment\ExpireReenrolmentRequests as Sweep;
use Illuminate\Console\Command;

/**
 * Closes re-enrolment requests past their 72 hours (spec 018, R-13).
 * Scheduled every five minutes in routes/console.php.
 */
final class ExpireReenrolmentRequests extends Command
{
    protected $signature = 'admin:expire-reenrolment-requests';

    protected $description = 'Close re-enrolment requests past their expiry as expired';

    public function handle(Sweep $sweep): int
    {
        $this->info('Expired '.$sweep->handle().' re-enrolment request(s).');

        return self::SUCCESS;
    }
}
