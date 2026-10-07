<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\GooglePlay\SweepPlay as Sweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:play-sweep')]
#[Description('Re-read stale Google Play subscriptions, revoke voided purchases, delete expired Play records')]
class SweepPlay extends Command
{
    public function handle(Sweep $sweep): int
    {
        $result = $sweep->run();
        $this->components->info("{$result['stale']} re-read, {$result['revoked']} revoked, {$result['purchases_deleted']} purchases and {$result['notifications_deleted']} notifications deleted.");

        return self::SUCCESS;
    }
}
