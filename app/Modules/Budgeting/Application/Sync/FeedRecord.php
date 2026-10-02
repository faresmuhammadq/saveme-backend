<?php

namespace App\Modules\Budgeting\Application\Sync;

/**
 * One row Budgeting offers the user feed: the record type name, its position
 * in the owner's user counter and the record as its read endpoint renders it.
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
