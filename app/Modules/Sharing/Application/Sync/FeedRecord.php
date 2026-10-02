<?php

namespace App\Modules\Sharing\Application\Sync;

/**
 * One row Sharing offers the wallet feed: the record type name, its position
 * in the wallet's counter and the record as its read endpoint renders it.
 */
final readonly class FeedRecord
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public string $type,
        public int $position,
        public array $record,
    ) {}
}
