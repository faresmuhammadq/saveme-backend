<?php

namespace App\Modules\Sync\Domain\Feed;

/**
 * One page of a feed: the rows, whether more follow, and the position the
 * client resumes from (the last row's, or the position it came with when the
 * page is empty).
 */
final readonly class FeedPage
{
    /**
     * @param  list<FeedRow>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $hasMore,
        public int $nextPosition,
    ) {}
}
