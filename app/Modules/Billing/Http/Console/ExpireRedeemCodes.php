<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\RedeemCodes\ExpireRedeemCodes as Sweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:expire-redeem-codes')]
#[Description('Expire issued redeem codes past their 30 days and close their claims')]
class ExpireRedeemCodes extends Command
{
    public function handle(Sweep $sweep): int
    {
        $this->components->info("{$sweep->run()} code(s) expired.");

        return self::SUCCESS;
    }
}
