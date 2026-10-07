<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\GooglePlay\ReconcilePlay as Reconcile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:play-reconcile')]
#[Description('Re-read Google Play subscriptions past their end, unacknowledged and pending purchases')]
class ReconcilePlay extends Command
{
    public function handle(Reconcile $reconcile): int
    {
        $result = $reconcile->run();
        $this->components->info("{$result['read']} read, {$result['unavailable']} unavailable, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
