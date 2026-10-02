<?php

use App\Modules\Sync\Domain\Batch\ItemOutcome;

test('an item ends from what its single write answered', function (int $status, ?string $code, string $outcome) {
    expect(ItemOutcome::fromResponse($status, $code))->toBe(ItemOutcome::from($outcome));
})->with([
    [200, null, 'applied'],
    [201, null, 'applied'],
    [409, 'VERSION_CONFLICT', 'conflict'],
    [409, 'CATEGORY_IN_USE', 'rejected'],
    [403, 'FORBIDDEN', 'rejected'],
    [404, 'NOT_FOUND', 'rejected'],
    [422, 'VALIDATION_FAILED', 'rejected'],
    [500, 'INTERNAL_ERROR', 'rejected'],
]);
