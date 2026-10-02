<?php

namespace App\Modules\Sync\Domain\Batch;

/**
 * What a batch item does to its record.
 */
enum ItemOperation: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
    case Restore = 'restore';
}
