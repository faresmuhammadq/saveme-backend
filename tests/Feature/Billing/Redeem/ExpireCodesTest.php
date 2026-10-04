<?php

use App\Modules\Billing\Application\RedeemCodes\ExpireRedeemCodes;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use Illuminate\Support\Facades\Artisan;

/*
 * The daily sweep that stores a code's expiry (spec 020, FR-022a).
 */

beforeEach(function () {
    $this->freezeTime();
});

function codeOnClaim(array $code = []): RedeemCode
{
    $claim = PaymentClaim::factory()->approved()->create();

    return RedeemCode::factory()->state(['claim_id' => $claim->id, ...$code])->create();
}

test('issued codes past their expiry become expired and close their claims', function () {
    $due = codeOnClaim(['expires_at' => now()->subSecond()]);

    expect(app(ExpireRedeemCodes::class)->run())->toBe(1);

    expect($due->fresh()->status)->toBe('expired')
        ->and(PaymentClaim::query()->findOrFail($due->claim_id)->closed_at->equalTo(now()))->toBeTrue();
});

test('codes not yet due, redeemed codes and revoked codes are untouched', function () {
    $live = codeOnClaim(['expires_at' => now()->addSecond()]);
    $redeemed = RedeemCode::factory()->redeemed()->state(['claim_id' => PaymentClaim::factory()->approved()->create()->id, 'expires_at' => now()->subDay()])->create();
    $revoked = RedeemCode::factory()->revoked()->state(['claim_id' => PaymentClaim::factory()->approved()->create()->id, 'expires_at' => now()->subDay()])->create();

    expect(app(ExpireRedeemCodes::class)->run())->toBe(0)
        ->and($live->fresh()->status)->toBe('issued')
        ->and($redeemed->fresh()->status)->toBe('redeemed')
        ->and($revoked->fresh()->status)->toBe('revoked')
        ->and(PaymentClaim::query()->whereNotNull('closed_at')->count())->toBe(0);
});

test('running it again changes nothing', function () {
    $due = codeOnClaim(['expires_at' => now()->subDay()]);

    app(ExpireRedeemCodes::class)->run();
    $this->travel(1)->day();

    expect(app(ExpireRedeemCodes::class)->run())->toBe(0)
        ->and(PaymentClaim::query()->findOrFail($due->claim_id)->closed_at->equalTo(now()->subDay()))->toBeTrue();
});

test('the command runs the sweep', function () {
    codeOnClaim(['expires_at' => now()->subDay()]);

    expect(Artisan::call('billing:expire-redeem-codes'))->toBe(0)->and(Artisan::output())->toContain('1 code(s) expired.');
});
