<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Claims\ExpireClaims as Sweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:expire-claims')]
#[Description('Close InstaPay claims unreviewed past 7 days as expired')]
class ExpireClaims extends Command
{
    public function handle(Sweep $sweep): int
    {
        $this->components->info("{$sweep->run()} claim(s) expired.");

        return self::SUCCESS;
    }
}
