<?php

namespace App\Modules\Identity\Http\Console;

use App\Modules\Identity\Application\Deletion\StartDuePurges;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('identity:purge-accounts')]
#[Description('Start or resume the purge of every account whose deletion grace period has ended')]
class PurgeAccounts extends Command
{
    public function handle(StartDuePurges $start): int
    {
        $done = $start->run();

        $this->components->info("{$done['dispatched']} purge(s) dispatched, {$done['stalled']} stalled reported, {$done['husks_deleted']} husk(s) deleted.");

        return self::SUCCESS;
    }
}
