<?php

use App\Modules\Billing\Application\Claims\PurgeClaims;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
 * Personal data erased 90 days after a claim closes, records deleted at 400
 * (spec 020, FR-039, SC-006).
 */

beforeEach(function () {
    $this->freezeTime();
    Storage::fake('local');
});

test('a claim closed 89 days ago is untouched', function () {
    $claim = PaymentClaim::factory()->withReceipt()->closedDaysAgo(89)->create();

    expect(app(PurgeClaims::class)->run())->toBe(['erased' => 0, 'deleted' => 0]);

    $claim->refresh();
    expect($claim->address_sealed)->not->toBeNull()->and($claim->address_lookup)->not->toBeNull()->and($claim->receipt_path)->not->toBeNull()->and($claim->erased_at)->toBeNull()
        ->and(Storage::disk('local')->exists("billing/claims/{$claim->id}/receipt"))->toBeTrue();
});

test('90 days after closing the address, lookup value and receipt are erased and the other facts stay', function () {
    $claim = PaymentClaim::factory()->withReceipt()->forAddress('mona@example.test')->closedDaysAgo(90)->create();
    $facts = $claim->only(['plan_id', 'amount_minor', 'currency', 'reference', 'status', 'locale']);

    expect(app(PurgeClaims::class)->run())->toBe(['erased' => 1, 'deleted' => 0]);

    $claim->refresh();
    expect($claim->address_sealed)->toBeNull()
        ->and($claim->address_lookup)->toBeNull()
        ->and($claim->receipt_path)->toBeNull()
        ->and($claim->receipt_media_type)->toBeNull()
        ->and($claim->receipt_bytes)->toBeNull()
        ->and($claim->erased_at->equalTo(now()))->toBeTrue()
        ->and($claim->only(['plan_id', 'amount_minor', 'currency', 'reference', 'status', 'locale']))->toBe($facts)
        ->and(Storage::disk('local')->exists("billing/claims/{$claim->id}/receipt"))->toBeFalse()
        ->and(Storage::disk('local')->directoryExists("billing/claims/{$claim->id}"))->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('rejected, expired and redeemed claims all count from the day they closed', function () {
    $rejected = PaymentClaim::factory()->rejected()->create(['closed_at' => now()->subDays(91)]);
    $redeemed = PaymentClaim::factory()->approved()->create(['closed_at' => now()->subDays(95)]);
    RedeemCode::factory()->redeemed()->state(['claim_id' => $redeemed->id])->create();

    expect(app(PurgeClaims::class)->run()['erased'])->toBe(2)
        ->and($rejected->fresh()->erased_at)->not->toBeNull()
        ->and($redeemed->fresh()->erased_at)->not->toBeNull();
});

test('an approved claim whose code is still issued is not closed, so it is not erased', function () {
    $claim = PaymentClaim::factory()->approved()->create(['created_at' => now()->subDays(200)]);
    RedeemCode::factory()->state(['claim_id' => $claim->id, 'expires_at' => now()->addDays(10)])->create();

    expect(app(PurgeClaims::class)->run())->toBe(['erased' => 0, 'deleted' => 0])
        ->and($claim->fresh()->address_sealed)->not->toBeNull();
});

test('400 days after closing the claim and its code record are deleted', function () {
    $old = PaymentClaim::factory()->approved()->create(['closed_at' => now()->subDays(400)]);
    RedeemCode::factory()->redeemed()->state(['claim_id' => $old->id])->create();
    $recent = PaymentClaim::factory()->approved()->create(['closed_at' => now()->subDays(399)]);
    RedeemCode::factory()->redeemed()->state(['claim_id' => $recent->id])->create();

    $result = app(PurgeClaims::class)->run();

    expect($result['deleted'])->toBe(1)
        ->and(PaymentClaim::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(RedeemCode::query()->pluck('claim_id')->all())->toBe([$recent->id]);
});

test('a claim old enough to delete that was never erased loses its receipt file too', function () {
    $claim = PaymentClaim::factory()->withReceipt()->closedDaysAgo(401)->create();

    app(PurgeClaims::class)->run();

    expect(PaymentClaim::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('running it again changes nothing', function () {
    $claim = PaymentClaim::factory()->withReceipt()->closedDaysAgo(120)->create();

    app(PurgeClaims::class)->run();
    $erasedAt = $claim->fresh()->erased_at;
    $this->travel(1)->day();

    expect(app(PurgeClaims::class)->run())->toBe(['erased' => 0, 'deleted' => 0])
        ->and($claim->fresh()->erased_at->equalTo($erasedAt))->toBeTrue();
});

test('the command runs the retention', function () {
    PaymentClaim::factory()->closedDaysAgo(120)->create();

    expect(Artisan::call('billing:purge-claims'))->toBe(0)->and(Artisan::output())->toContain('1 claim(s) erased, 0 deleted.');
});
