<?php

namespace App\Modules\Notifications\Http\Console;

use App\Modules\Notifications\Application\Delivery\ReleaseHeld;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:release-held')]
#[Description('Release held notifications whose quiet hours ended, and re-queue notifications stuck sending')]
class ReleaseHeldCommand extends Command
{
    public function handle(ReleaseHeld $sweep): int
    {
        $counts = $sweep->run();

        $this->components->info("{$counts['released']} released, {$counts['collapsed']} collapsed, {$counts['skipped']} skipped, {$counts['requeued']} re-queued.");

        return self::SUCCESS;
    }
}
