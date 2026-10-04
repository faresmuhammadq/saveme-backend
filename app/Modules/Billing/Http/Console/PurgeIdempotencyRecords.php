<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Idempotency\PurgeIdempotencyRecords as Purge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:purge-idempotency-records')]
#[Description('Delete idempotency records past their 24 hours')]
class PurgeIdempotencyRecords extends Command
{
    public function handle(Purge $purge): int
    {
        $this->components->info("{$purge->run()} idempotency record(s) deleted.");

        return self::SUCCESS;
    }
}
