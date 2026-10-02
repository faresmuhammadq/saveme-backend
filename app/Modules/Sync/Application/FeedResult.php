<?php

namespace App\Modules\Sync\Application;

use App\Modules\Sync\Domain\Feed\FeedRow;

/**
 * A feed page ready for the wire: the rows, the page size applied, and the
 * signed cursor of the position after the page, and that position as an integer
 * (`sync_position`, ADR-0021). `next_cursor` is the same
 * cursor while more follow, else null.
 */
final readonly class FeedResult
{
    /**
     * @param  list<FeedRow>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $hasMore,
        public int $limit,
        public string $syncCursor,
        public int $syncPosition,
    ) {}
}
