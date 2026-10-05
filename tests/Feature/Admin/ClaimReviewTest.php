<?php

use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\ClaimReview;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Billing\Application\Jobs\SendClaimApprovedMail;
use App\Modules\Billing\Application\Jobs\SendClaimRejectedMail;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The claim review (spec 021, US1; FR-016 to FR-019, R-3, R-13).
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    ['operator' => $this->operator] = makeOperator(attributes: ['name' => 'Reviewer Rana']);
    signInOperator($this->operator);
});

function claimReadEntries(string $claimId): array
{
    return DB::table('audit_entries')->where('event', 'admin_action')->where('subject_type', 'payment_claim')
        ->where('subject_id', $claimId)->whereRaw("detail->>'action' = 'claim_address_read'")->get()->all();
}

function claimReviewedEntries(string $claimId): array
{
    return DB::table('audit_entries')->where('event', 'claim_reviewed')->where('subject_id', $claimId)->get()->all();
}

it('writes exactly one claim_address_read before the address renders, and none on re-render or action', function () {
    $claim = PaymentClaim::factory()->forAddress('payer@example.test')->create();

    $component = Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->assertSee('payer@example.test')
        ->assertSeeHtml('data-test="payer-address"');

    $entries = claimReadEntries($claim->id);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('operator')
        ->and($entries[0]->actor_id)->toBe($this->operator->id);

    $component->call('$refresh')->call('confirmApprove');
    $component->call('getReceiptLink');

    expect(claimReadEntries($claim->id))->toHaveCount(1);
});

it('answers 404 for an unknown claim and writes nothing', function () {
    $this->get(route('admin.claims.show', ['claim' => (string) Str::uuid7()]))->assertNotFound();

    expect(DB::table('audit_entries')->where('event', 'admin_action')->count())->toBe(0);
});

it('shows payment facts, the price match, the language and the other claims with the reference', function () {
    $claim = PaymentClaim::factory()->reference('SAMEREF00001')->inArabic()->create();
    $other = PaymentClaim::factory()->reference('SAMEREF00001')->rejected()->create();

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->assertSee('SAMEREF00001')
        ->assertSee('Plus · Yearly')
        ->assertSee('Arabic')
        ->assertSeeHtml('data-test="matches-price"')
        ->assertSeeHtml('data-test="same-reference"')
        ->assertSeeHtml(route('admin.claims.show', ['claim' => $other->id]));
});

it('says when the amount no longer matches the plan price', function () {
    $claim = PaymentClaim::factory()->create(['amount_minor' => 100000]);

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])->assertSeeInOrder(['Matches the current InstaPay price', 'No']);
});

it('offers actions only in the allowed states', function (string $state, array $visible, array $hidden) {
    $factory = PaymentClaim::factory();
    $claim = match ($state) {
        'submitted' => $factory->create(),
        'lapsed' => $factory->create(['expires_at' => now()->subMinute()]),
        'approved' => tap($factory->approved()->create(), fn ($c) => RedeemCode::factory()->create(['claim_id' => $c->id])),
        'approved-redeemed' => tap($factory->approved()->create(), fn ($c) => RedeemCode::factory()->redeemed()->create(['claim_id' => $c->id])),
        'rejected' => $factory->rejected()->create(),
        'expired' => $factory->expired()->create(),
    };

    $component = Livewire::test(ClaimReview::class, ['claim' => $claim->id]);

    foreach ($visible as $hook) {
        $component->assertSeeHtml("data-test=\"{$hook}\"");
    }

    foreach ($hidden as $hook) {
        $component->assertDontSeeHtml("data-test=\"{$hook}\"");
    }
})->with([
    'submitted' => ['submitted', ['approve', 'reject'], ['revoke-code']],
    'submitted past its expiry' => ['lapsed', [], ['approve', 'reject', 'revoke-code']],
    'approved with an issued code' => ['approved', ['revoke-code'], ['approve', 'reject']],
    'approved with a redeemed code' => ['approved-redeemed', [], ['approve', 'reject', 'revoke-code']],
    'rejected' => ['rejected', [], ['approve', 'reject', 'revoke-code']],
    'expired' => ['expired', [], ['approve', 'reject', 'revoke-code']],
]);

it('approves with a fresh proof: one claim_reviewed by the operator, the mail queued, the review refreshed', function () {
    $claim = PaymentClaim::factory()->create();

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->call('confirmApprove')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Claim approved. The code is on its way.')
        ->assertSet('review.status', 'approved')
        ->assertSee('Reviewer Rana');

    $entries = claimReviewedEntries($claim->id);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($this->operator->id)
        ->and(json_decode($entries[0]->detail, true))->toBe(['outcome' => 'approved'])
        ->and(PaymentClaim::query()->find($claim->id)->status)->toBe('approved');

    Queue::assertPushed(SendClaimApprovedMail::class);
    expect(claimReadEntries($claim->id))->toHaveCount(1);
});

it('warns about a shared reference in the approve dialog', function () {
    $claim = PaymentClaim::factory()->reference('SAMEREF00001')->create();
    PaymentClaim::factory()->reference('SAMEREF00001')->create();

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])->assertSeeHtml('data-test="approve-duplicate-warning"');
});

it('rejects with a reason and an internal note shown on the review', function () {
    $claim = PaymentClaim::factory()->create();

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->set('rejectReason', 'amount_mismatch')
        ->set('rejectNote', 'Paid 100 EGP, not 1,290')
        ->call('confirmReject')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Claim rejected.')
        ->assertSet('review.status', 'rejected')
        ->assertSet('rejectNote', '')
        ->assertSee('Amount doesn’t match')
        ->assertSee('Paid 100 EGP, not 1,290');

    $row = PaymentClaim::query()->find($claim->id);
    expect($row->reject_reason)->toBe('amount_mismatch')->and($row->review_note)->toBe('Paid 100 EGP, not 1,290')
        ->and(claimReviewedEntries($claim->id))->toHaveCount(1);

    Queue::assertPushed(SendClaimRejectedMail::class);
});

it('validates the reject reason and the note length before any proof', function () {
    $claim = PaymentClaim::factory()->create();
    $component = Livewire::test(ClaimReview::class, ['claim' => $claim->id]);

    $component->set('rejectReason', 'because')->call('confirmReject')
        ->assertHasErrors('rejectReason')->assertSet('factorOpen', false);

    $component->set('rejectReason', 'other')->set('rejectNote', str_repeat('a', 501))->call('confirmReject')
        ->assertHasErrors('rejectNote');

    expect(PaymentClaim::query()->find($claim->id)->status)->toBe('submitted')->and(claimReviewedEntries($claim->id))->toBe([]);

    $component->set('rejectNote', str_repeat('a', 500))->call('confirmReject')->assertHasNoErrors();
    expect(PaymentClaim::query()->find($claim->id)->status)->toBe('rejected');
});

it('revokes an issued code through a reason, then the proof', function () {
    $claim = PaymentClaim::factory()->approved()->create();
    $code = RedeemCode::factory()->create(['claim_id' => $claim->id]);

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->call('revokeCode')
        ->assertSet('reasonOpen', true)
        ->call('confirmReason', 'Paid twice by mistake')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Code revoked.')
        ->assertSet('review.code_status', 'revoked')
        ->assertDontSeeHtml('data-test="revoke-code"');

    expect(RedeemCode::query()->find($code->id)->status)->toBe('revoked')
        ->and(json_decode(claimReviewedEntries($claim->id)[0]->detail, true))->toBe(['outcome' => 'code_revoked']);
});

it('refuses a blank revoke reason', function () {
    $claim = PaymentClaim::factory()->approved()->create();
    RedeemCode::factory()->create(['claim_id' => $claim->id]);

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->call('revokeCode')->call('confirmReason', '   ')->assertHasErrors('reason');

    expect(claimReviewedEntries($claim->id))->toBe([]);
});

it('maps each refusal to its message and changes nothing', function (Closure $arrange, string $act, string $message) {
    $claim = PaymentClaim::factory()->reference('REFUSAL00001')->create();
    $component = Livewire::test(ClaimReview::class, ['claim' => $claim->id]);

    $arrange($claim);

    match ($act) {
        'approve' => $component->call('confirmApprove'),
        'revoke' => $component->call('revokeCode')->call('confirmReason', 'a reason'),
    };

    $component->assertDispatched('admin-toast', tone: 'error', message: $message);
    expect(claimReviewedEntries($claim->id))->toBe([]);
})->with([
    'claim already reviewed' => [fn ($claim) => PaymentClaim::query()->whereKey($claim->id)->update(['status' => 'rejected', 'closed_at' => now(), 'reviewed_by' => (string) Str::uuid7(), 'reviewed_at' => now(), 'reject_reason' => 'other']), 'approve', 'This claim can no longer be reviewed.'],
    'claim lapsed' => [fn ($claim) => PaymentClaim::query()->whereKey($claim->id)->update(['expires_at' => now()->subSecond()]), 'approve', 'This claim can no longer be reviewed.'],
    'reference already approved' => [fn ($claim) => PaymentClaim::factory()->reference('REFUSAL00001')->approved()->create(), 'approve', 'Another claim with this reference is already approved.'],
    'plan retired' => [fn ($claim) => Plan::query()->whereKey($claim->plan_id)->update(['status' => 'retired']), 'approve', 'This plan can no longer be granted.'],
    'code not revocable' => [function ($claim) {
        PaymentClaim::query()->whereKey($claim->id)->update(['status' => 'approved', 'reviewed_by' => (string) Str::uuid7(), 'reviewed_at' => now()]);
        RedeemCode::factory()->redeemed()->create(['claim_id' => $claim->id]);
    }, 'revoke', 'This code can no longer be revoked.'],
]);

it('issues a receipt link only when pressed: signed, 5 minutes, and writes nothing itself', function () {
    $claim = PaymentClaim::factory()->withReceipt()->create();
    $component = Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->assertSeeHtml('data-test="receipt-link"')
        ->assertDontSeeHtml('data-test="receipt-open"')
        ->assertSet('receiptUrl', '');

    $before = DB::table('audit_entries')->count();
    $component->call('getReceiptLink')->assertSeeHtml('data-test="receipt-open"')->assertSee('Open receipt (link valid 5 minutes)');

    $url = $component->get('receiptUrl');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(parse_url($url, PHP_URL_PATH))->toBe("/admin/claims/{$claim->id}/receipt")
        ->and((int) $query['expires'])->toBe(now()->addMinutes(5)->getTimestamp())
        ->and(DB::table('audit_entries')->count())->toBe($before);
});

it('shows no receipt when there is none, and Erased for an erased claim', function () {
    $none = PaymentClaim::factory()->create();
    $erased = PaymentClaim::factory()->closedDaysAgo(100)->withReceipt()->create();
    PaymentClaim::query()->whereKey($erased->id)->update(['address_sealed' => null, 'address_lookup' => null, 'receipt_path' => null, 'receipt_media_type' => null, 'receipt_bytes' => null, 'erased_at' => now()]);

    Livewire::test(ClaimReview::class, ['claim' => $none->id])->assertSee('No receipt')->assertDontSeeHtml('data-test="receipt-link"');
    Livewire::test(ClaimReview::class, ['claim' => $erased->id])
        ->assertSeeHtml('data-test="receipt-erased"')
        ->assertSeeHtml('data-test="payer-erased"')
        ->assertSee('Erased after retention')
        ->assertDontSeeHtml('data-test="receipt-link"');
});

it('shows the reviewer name, the instant, the code status and expiry once reviewed', function () {
    $claim = PaymentClaim::factory()->approved($this->operator->id)->create();
    RedeemCode::factory()->create(['claim_id' => $claim->id]);

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->assertSeeHtml('data-test="review-card"')
        ->assertSee('Reviewer Rana')
        ->assertSee('Issued');
});

it('renders in Arabic right to left, with Western digits and the reference left to right', function () {
    $claim = PaymentClaim::factory()->reference('ARREF0000001')->create();

    $response = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])
        ->get(route('admin.claims.show', ['claim' => $claim->id]))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('المطالبة')
        ->assertSee('<bdi dir="ltr"', false);

    expect($response->getContent())->toUseWesternDigitsOnly();
});

it('keeps the pending approval locked: a stale proof opens the prompt and the claim stays submitted', function () {
    $claim = PaymentClaim::factory()->create();
    session()->put(OperatorSession::FACTOR_CONFIRMED_AT, now()->subMinutes(11)->getTimestamp());

    Livewire::test(ClaimReview::class, ['claim' => $claim->id])
        ->call('confirmApprove')
        ->assertSet('factorOpen', true);

    expect(PaymentClaim::query()->find($claim->id)->status)->toBe('submitted');
});
