<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Subscriptions\LapseSubscriptions as Lapse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:lapse-subscriptions')]
#[Description('Move subscriptions past their billing period into grace, and past grace into expired')]
class LapseSubscriptions extends Command
{
    public function handle(Lapse $lapse): int
    {
        $result = $lapse->run();
        $this->components->info("{$result['graced']} into grace, {$result['expired']} expired.");

        return self::SUCCESS;
    }
}
