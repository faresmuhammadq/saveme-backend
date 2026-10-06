<?php

namespace App\Modules\Notifications\Http\Console;

use App\Modules\Notifications\Application\Reminders\SendOccurrenceReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:occurrence-reminders')]
#[Description('Remind people of recurring occurrences that need them, not before 09:00 their time')]
class OccurrenceRemindersCommand extends Command
{
    public function handle(SendOccurrenceReminders $pass): int
    {
        $counts = $pass->run();

        $this->components->info("{$counts['created']} reminder(s) created, {$counts['summarised']} folded into a summary, {$counts['skipped']} person(s) not yet at 09:00.");

        return self::SUCCESS;
    }
}
