<?php

use App\Modules\Admin\Domain\AnonymityFloor;

/*
| The smallest non-zero population count the back office shows exactly
| (spec 021, FR-011, R-11).
*/

it('shows zero and counts of ten or more exactly', function (int $count) {
    expect(AnonymityFloor::display($count))->toBe($count);
})->with([0, 10, 11, 12345]);

it('hides counts of one to nine behind the marker', function (int $count) {
    expect(AnonymityFloor::display($count))->toBe('fewer_than_10');
})->with([1, 2, 9]);

it('refuses a negative count', function () {
    AnonymityFloor::display(-1);
})->throws(InvalidArgumentException::class);
