<?php

namespace App\Modules\Identity\Application\Sync;

/**
 * One row Identity offers the user feed: the record type name, its position
 * in the user counter and the record as its read endpoint renders it.
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
