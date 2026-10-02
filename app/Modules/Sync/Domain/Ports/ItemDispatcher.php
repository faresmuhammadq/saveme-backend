<?php

namespace App\Modules\Sync\Domain\Ports;

use App\Modules\Sync\Domain\Batch\BatchItem;

/**
 * Performs one batch item as the per-record write it names and reports the
 * status and decoded body that write answered (spec 013, R-2). Implemented in
 * Http, which owns routes; the Domain only knows the port.
 */
interface ItemDispatcher
{
    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function dispatch(BatchItem $item): array;
}
