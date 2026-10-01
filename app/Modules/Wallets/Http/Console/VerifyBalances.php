<?php

namespace App\Modules\Wallets\Http\Console;

use App\Modules\Wallets\Application\Balances\VerifyBalances as Verify;
use Illuminate\Console\Command;

/**
 * Recomputes every live wallet's cached balance and repairs drift (ADR-0005 rule 6).
 */
final class VerifyBalances extends Command
{
    protected $signature = 'wallets:verify-balances';

    protected $description = 'Recompute wallet balances from opening balance and postings, logging and repairing drift';

    public function handle(Verify $verify): int
    {
        $drifted = $verify->handle();

        $this->info("Checked wallet balances; {$drifted} drifted and repaired.");

        return self::SUCCESS;
    }
}
