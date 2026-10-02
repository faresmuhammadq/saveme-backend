<?php

use App\Modules\Sync\Domain\Feed\FeedMerge;
use App\Modules\Sync\Domain\Feed\FeedRow;

/*
 * The k-way merge by position (spec 013, R-8).
 */

function feedRows(string $type, int ...$positions): array
{
    return array_map(fn (int $position): FeedRow => new FeedRow($type, $position, ['p' => $position]), $positions);
}

test('the lowest limit positions of all sources are taken, in order', function () {
    $page = FeedMerge::merge([feedRows('a', 1, 4, 7), feedRows('b', 2, 3, 9)], 0, 4);

    expect(array_map(fn (FeedRow $row): int => $row->position, $page->rows))->toBe([1, 2, 3, 4])
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextPosition)->toBe(4);
});

test('no more is reported when every source is exhausted', function () {
    $page = FeedMerge::merge([feedRows('a', 1, 2), feedRows('b', 3)], 0, 3);

    expect($page->rows)->toHaveCount(3)->and($page->hasMore)->toBeFalse()->and($page->nextPosition)->toBe(3);
});

test('an empty page returns the incoming position', function () {
    $page = FeedMerge::merge([[], []], 17, 10);

    expect($page->rows)->toBe([])->and($page->hasMore)->toBeFalse()->and($page->nextPosition)->toBe(17);
});

test('a row above the counter read first is dropped, so a later-stamped row never hides an earlier one', function () {
    $page = FeedMerge::merge([feedRows('a', 1, 2), feedRows('b', 5)], 0, 10, 3);

    expect(array_map(fn (FeedRow $row): int => $row->position, $page->rows))->toBe([1, 2])
        ->and($page->hasMore)->toBeFalse();
});
