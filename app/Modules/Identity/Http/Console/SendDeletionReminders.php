<?php

namespace App\Modules\Identity\Http\Console;

use App\Modules\Identity\Application\Deletion\SendDeletionReminders as Reminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('identity:send-deletion-reminders')]
#[Description('Remind people whose account will be deleted within three days, once')]
class SendDeletionReminders extends Command
{
    public function handle(Reminders $reminders): int
    {
        $this->components->info($reminders->run().' reminder(s) queued.');

        return self::SUCCESS;
    }
}
