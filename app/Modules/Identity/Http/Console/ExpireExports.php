<?php

namespace App\Modules\Identity\Http\Console;

use App\Modules\Identity\Application\Export\ExpireExports as Expire;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('identity:expire-exports')]
#[Description('Expire ready exports, fail stale ones, delete old export records and stray files')]
class ExpireExports extends Command
{
    public function handle(Expire $expire): int
    {
        $done = $expire->run();

        $this->components->info("{$done['expired']} expired, {$done['timed_out']} timed out, {$done['deleted']} record(s) deleted, {$done['orphans']} stray file(s) removed.");

        return self::SUCCESS;
    }
}
