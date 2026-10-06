<?php

namespace App\Modules\Notifications\Http\Console;

use App\Modules\Notifications\Application\PruneOldRecords;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:prune')]
#[Description('Delete push registrations idle for 270 days and notification records older than 30 days')]
class PruneCommand extends Command
{
    public function handle(PruneOldRecords $prune): int
    {
        $counts = $prune->run();

        $this->components->info("{$counts['registrations']} idle registration(s) and {$counts['notifications']} old notification(s) deleted.");

        return self::SUCCESS;
    }
}
