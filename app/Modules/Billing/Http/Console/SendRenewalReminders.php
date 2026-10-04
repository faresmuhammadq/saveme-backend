<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Reminders\SendRenewalReminders as Sweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:send-renewal-reminders')]
#[Description('Mail a renewal reminder for InstaPay subscriptions ending within 7 days')]
class SendRenewalReminders extends Command
{
    public function handle(Sweep $sweep): int
    {
        $this->components->info("{$sweep->run()} reminder(s) queued.");

        return self::SUCCESS;
    }
}
