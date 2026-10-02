<?php

namespace App\Modules\Wallets\Application\Sync;

/**
 * One row a Wallets source offers a change feed: the record type name, its
 * position in the scope's counter and the record exactly as its read
 * endpoint renders it (spec 013, R-8).
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
