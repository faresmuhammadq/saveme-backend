<?php

namespace App\Modules\Sync\Domain\Feed;

/**
 * The k-way merge of position-ordered sources (spec 013, R-8). Each source
 * gives rows above the incoming position, at most `limit + 1` of them; the
 * merge keeps the lowest `limit` positions, and reports more when any row is
 * left over. Positions of one scope come from one counter, so they are unique
 * and the merged order is the commit order.
 */
final class FeedMerge
{
    /**
     * Rows above `$upTo`, the scope counter read before the sources were, are
     * dropped: a source read a moment later can hold a row stamped after that
     * read, and serving it while an earlier-stamped row of another source was
     * missed by its own read would leave a gap. Everything at or below the
     * counter is committed, so every source sees it.
     *
     * @param  list<list<FeedRow>>  $sources  each ordered by position
     */
    public static function merge(array $sources, int $after, int $limit, int $upTo = PHP_INT_MAX): FeedPage
    {
        $all = array_values(array_filter(
            array_merge(...$sources === [] ? [[]] : $sources),
            fn (FeedRow $row): bool => $row->position <= $upTo,
        ));

        usort($all, fn (FeedRow $a, FeedRow $b): int => $a->position <=> $b->position);

        $rows = array_slice($all, 0, $limit);

        return new FeedPage(
            $rows,
            count($all) > $limit,
            $rows === [] ? $after : $rows[array_key_last($rows)]->position,
        );
    }
}
