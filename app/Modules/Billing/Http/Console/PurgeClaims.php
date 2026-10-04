<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Claims\PurgeClaims as Sweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:purge-claims')]
#[Description('Erase the address and receipt of closed claims after 90 days, delete the records after 400')]
class PurgeClaims extends Command
{
    public function handle(Sweep $sweep): int
    {
        $result = $sweep->run();
        $this->components->info("{$result['erased']} claim(s) erased, {$result['deleted']} deleted.");

        return self::SUCCESS;
    }
}
