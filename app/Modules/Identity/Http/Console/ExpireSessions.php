<?php

namespace App\Modules\Identity\Http\Console;

use App\Modules\Identity\Application\Sessions\ExpireIdleSessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('identity:expire-sessions')]
#[Description('Record device sessions idle past the inactivity window as expired')]
class ExpireSessions extends Command
{
    public function handle(ExpireIdleSessions $expire): int
    {
        $this->components->info($expire->handle().' session(s) expired.');

        return self::SUCCESS;
    }
}
