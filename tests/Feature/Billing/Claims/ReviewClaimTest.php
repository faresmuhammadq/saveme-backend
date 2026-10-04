<?php

use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Application\Jobs\SendClaimApprovedMail;
use App\Modules\Billing\Application\Jobs\SendClaimRejectedMail;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Domain\Refusals\ClaimNotReviewable;
use App\Modules\Billing\Domain\Refusals\CodeNotRevocable;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\InvalidRejectReason;
use App\Modules\Billing\Domain\Refusals\PlanNotGrantable;
use App\Modules\Billing\Domain\Refusals\ReferenceAlreadyApproved;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\RedeemCode as RedeemCodeRow;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Approve, reject and revoke a code (spec 020, FR-015 to FR-021, FR-024, FR-036).
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    $this->operator = (string) Str::uuid7();
    $this->claims = app(ClaimService::class);
});

function reviewEntries(): array
{
    return AuditEntry::query()->where('event', 'claim_reviewed')->get()->all();
}

test('approving marks the claim, issues one code as a hash only, audits once and queues the mail', function () {
    $claim = PaymentClaim::factory()->create();

    $this->claims->approve($this->operator, $claim->id);

    $claim->refresh();
    $code = RedeemCodeRow::query()->sole();
    expect($claim->status)->toBe('approved')
        ->and($claim->reviewed_by)->toBe($this->operator)
        ->and($claim->reviewed_at->equalTo(now()))->toBeTrue()
        ->and($claim->closed_at)->toBeNull()
        ->and($code->claim_id)->toBe($claim->id)
        ->and($code->status)->toBe('issued')
        ->and($code->expires_at->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($code->code_hash)->toMatch('/^[0-9a-f]{64}$/');

    $entry = reviewEntries();
    expect($entry)->toHaveCount(1)
        ->and($entry[0])->actor_type->toBe('operator')->actor_id->toBe($this->operator)->subject_type->toBe('payment_claim')->subject_id->toBe($claim->id)
        ->and($entry[0]->detail)->toEqual(['outcome' => 'approved']);

    $plain = null;
    Queue::assertPushed(SendClaimApprovedMail::class, function (SendClaimApprovedMail $job) use ($claim, &$plain): bool {
        $plain = $job->code;

        return $job->claimId === $claim->id && $job instanceof ShouldBeEncrypted;
    });

    expect(RedeemCode::tryParse($plain)->hash())->toBe($code->code_hash)
        ->and($plain)->toMatch('/^[0-9A-Z]{4}(-[0-9A-Z]{4}){3}$/');

    // No column of any record, and no audit detail, holds the code.
    $everything = json_encode([DB::table('payment_claims')->get(), DB::table('redeem_codes')->get(), DB::table('audit_entries')->get()]);
    expect($everything)->not->toContain($plain)->not->toContain(str_replace('-', '', $plain));
});

test('rejecting keeps the note for operators, audits the reason without it, and queues the mail', function (string $reason) {
    $claim = PaymentClaim::factory()->create();

    $this->claims->reject($this->operator, $claim->id, $reason, '  Customer phoned 0100 123 4567  ');

    $claim->refresh();
    expect($claim->status)->toBe('rejected')
        ->and($claim->reject_reason)->toBe($reason)
        ->and($claim->review_note)->toBe('Customer phoned 0100 123 4567')
        ->and($claim->reviewed_by)->toBe($this->operator)
        ->and($claim->closed_at->equalTo(now()))->toBeTrue()
        ->and(RedeemCodeRow::query()->count())->toBe(0);

    $entry = reviewEntries();
    expect($entry)->toHaveCount(1)
        ->and($entry[0]->detail)->toEqual(['outcome' => 'rejected', 'reason' => $reason])
        ->and(json_encode(DB::table('audit_entries')->get()))->not->toContain('0100');
    Queue::assertPushed(SendClaimRejectedMail::class, fn (SendClaimRejectedMail $job): bool => $job->claimId === $claim->id && $job instanceof ShouldBeEncrypted);
})->with(['reference_not_found', 'amount_mismatch', 'duplicate', 'other']);

test('a claim is reviewed once: a second approve or reject, or any review of a closed claim, is refused and changes nothing', function (string $setup) {
    $claim = match ($setup) {
        'approved' => PaymentClaim::factory()->approved()->create(),
        'rejected' => PaymentClaim::factory()->rejected()->create(),
        'expired' => PaymentClaim::factory()->expired()->create(),
        'past expiry, sweep not run' => PaymentClaim::factory()->create(['expires_at' => now()->subSecond()]),
    };
    $before = $claim->fresh()->only(['status', 'reviewed_by', 'reject_reason', 'closed_at']);

    expect(fn () => $this->claims->approve($this->operator, $claim->id))->toThrow(ClaimNotReviewable::class)
        ->and(fn () => $this->claims->reject($this->operator, $claim->id, 'other'))->toThrow(ClaimNotReviewable::class);

    expect($claim->fresh()->only(['status', 'reviewed_by', 'reject_reason', 'closed_at']))->toEqual($before)
        ->and(RedeemCodeRow::query()->count())->toBe(0)
        ->and(reviewEntries())->toBe([]);
    Queue::assertNothingPushed();
})->with(['approved', 'rejected', 'expired', 'past expiry, sweep not run']);

test('an unknown claim cannot be reviewed', function () {
    expect(fn () => $this->claims->approve($this->operator, (string) Str::uuid7()))->toThrow(ClaimNotReviewable::class);
});

test('approval is refused when the plan was retired or no longer has an InstaPay price', function (string $change) {
    $claim = PaymentClaim::factory()->create();
    $plan = Plan::query()->findOrFail($claim->plan_id);
    $plan->update($change === 'retired' ? ['status' => 'retired'] : ['prices' => []]);

    expect(fn () => $this->claims->approve($this->operator, $claim->id))->toThrow(PlanNotGrantable::class);
    expect($claim->fresh()->status)->toBe('submitted');

    // The operator may still reject it.
    $this->claims->reject($this->operator, $claim->id, 'other');
    expect($claim->fresh()->status)->toBe('rejected');
})->with(['retired', 'no price']);

test('a reference already approved on another claim cannot be approved again', function () {
    $first = PaymentClaim::factory()->reference('SAMEREF0001')->create();
    $second = PaymentClaim::factory()->reference('SAMEREF0001')->create();

    $this->claims->approve($this->operator, $first->id);

    expect(fn () => $this->claims->approve($this->operator, $second->id))->toThrow(ReferenceAlreadyApproved::class);

    expect($second->fresh()->status)->toBe('submitted')
        ->and(RedeemCodeRow::query()->count())->toBe(1)
        ->and(reviewEntries())->toHaveCount(1);
    Queue::assertPushed(SendClaimApprovedMail::class, 1);

    // It can be rejected as a duplicate.
    $this->claims->reject($this->operator, $second->id, 'duplicate');
    expect($second->fresh()->status)->toBe('rejected');
});

test('a reject reason outside the list, or a note over 500 characters, is refused', function () {
    $claim = PaymentClaim::factory()->create();

    expect(fn () => $this->claims->reject($this->operator, $claim->id, 'because'))->toThrow(InvalidRejectReason::class)
        ->and(fn () => $this->claims->reject($this->operator, $claim->id, 'other', str_repeat('x', 501)))->toThrow(InvalidRejectReason::class);

    $this->claims->reject($this->operator, $claim->id, 'other', str_repeat('é', 500));
    expect($claim->fresh()->status)->toBe('rejected');
});

test('revoking voids an issued code, closes the claim, audits without free text and sends no mail', function () {
    $claim = PaymentClaim::factory()->create();
    $this->claims->approve($this->operator, $claim->id);
    Queue::fake();

    $this->claims->revokeCode($this->operator, $claim->id, 'Approved the wrong customer, phone 0100 123 4567');

    $code = RedeemCodeRow::query()->sole();
    expect($code->status)->toBe('revoked')
        ->and($code->revoked_at->equalTo(now()))->toBeTrue()
        ->and($claim->fresh()->closed_at->equalTo(now()))->toBeTrue();

    $entries = reviewEntries();
    expect($entries)->toHaveCount(2)
        ->and($entries[1]->detail)->toEqual(['outcome' => 'code_revoked'])
        ->and(json_encode(DB::table('audit_entries')->get()))->not->toContain('0100');
    Queue::assertNothingPushed();
});

test('a redeemed, expired or already revoked code cannot be revoked', function (string $state) {
    $claim = PaymentClaim::factory()->approved()->create();
    $code = RedeemCodeRow::factory()->state(['claim_id' => $claim->id])->{['redeemed' => 'redeemed', 'expired' => 'expiredCode', 'revoked' => 'revoked'][$state]}()->create();

    expect(fn () => $this->claims->revokeCode($this->operator, $claim->id, 'mistake'))->toThrow(CodeNotRevocable::class)
        ->and($code->fresh()->status)->toBe($state)
        ->and(reviewEntries())->toBe([]);
})->with(['redeemed', 'expired', 'revoked']);

test('revoking a claim that has no code, or with an empty or over-long reason, is refused', function () {
    $submitted = PaymentClaim::factory()->create();
    $approved = PaymentClaim::factory()->approved()->create();
    RedeemCodeRow::factory()->state(['claim_id' => $approved->id])->create();

    expect(fn () => $this->claims->revokeCode($this->operator, $submitted->id, 'mistake'))->toThrow(CodeNotRevocable::class)
        ->and(fn () => $this->claims->revokeCode($this->operator, $approved->id, '   '))->toThrow(InvalidReason::class)
        ->and(fn () => $this->claims->revokeCode($this->operator, $approved->id, str_repeat('x', 501)))->toThrow(InvalidReason::class)
        ->and(RedeemCodeRow::query()->sole()->status)->toBe('issued');
});
