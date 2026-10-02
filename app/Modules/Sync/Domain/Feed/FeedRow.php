<?php

namespace App\Modules\Sync\Domain\Feed;

/**
 * A Change Row (glossary): one record on a feed, its type plus the record
 * exactly as its read returns it, at its position in the scope's counter.
 */
final readonly class FeedRow
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
