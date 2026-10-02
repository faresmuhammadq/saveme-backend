<?php

namespace App\Modules\Sharing\Http\Console;

use App\Modules\Sharing\Application\ExpireInvitations as Sweep;
use Illuminate\Console\Command;

/**
 * The hourly sweep (spec 016, R-14): closes pending invitations past their
 * expiry as `expired`. Idempotent; scheduled in routes/console.php.
 */
final class ExpireInvitations extends Command
{
    protected $signature = 'sharing:expire-invitations';

    protected $description = 'Close pending invitations past their expiry as expired';

    public function handle(Sweep $sweep): int
    {
        $closed = $sweep->run();

        $this->info("Closed {$closed} expired invitation(s).");

        return self::SUCCESS;
    }
}
