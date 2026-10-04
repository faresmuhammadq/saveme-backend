<?php

use App\Modules\Identity\Application\Audit\Details\ClaimReviewedDetail;

/*
 * A claim_reviewed entry carries the outcome and, for a rejection, the reason
 * from the fixed list. No note, no free text (spec 020, FR-036).
 */

test('an approval carries only its outcome', function () {
    expect((new ClaimReviewedDetail('approved'))->toArray())->toBe(['outcome' => 'approved']);
});

test('a rejection carries its reason', function () {
    expect((new ClaimReviewedDetail('rejected', 'amount_mismatch'))->toArray())->toBe(['outcome' => 'rejected', 'reason' => 'amount_mismatch']);
});

test('a revoked code never carries a reason, whatever is passed', function () {
    expect((new ClaimReviewedDetail('code_revoked', 'customer asked by phone'))->toArray())->toBe(['outcome' => 'code_revoked']);
});
