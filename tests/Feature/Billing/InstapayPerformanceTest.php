<?php

use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode as RedeemCodeRow;
use Illuminate\Support\Facades\Storage;

/*
 * SC-007 (spec 020): claim submission with a 3 MiB receipt answers in under
 * 800 ms and redemption in under 300 ms at the 95th percentile, after warm-up,
 * on the development stack with debugging extensions off. It runs only on
 * request:
 * `INSTAPAY_PERF=1 XDEBUG_MODE=off dev exec -- vendor/bin/pest tests/Feature/Billing/InstapayPerformanceTest.php`.
 */

function instapayPercentile95(array $milliseconds): float
{
    sort($milliseconds);

    return $milliseconds[(int) ceil(0.95 * count($milliseconds)) - 1];
}

test('a claim with a 3 MiB receipt and a redemption stay inside the targets', function () {
    Storage::fake('local');
    config(['billing.instapay.throttle.claims_per_hour_per_origin' => 100000, 'billing.instapay.throttle.claims_per_day_per_address' => 100000, 'billing.instapay.throttle.redeem_per_hour' => 100000]);

    $receipt = ['media_type' => 'image/png', 'data' => receiptBase64('png', 3145728)];
    $submit = fn (int $n) => test()->withHeaders(claimHeaders())->postJson('/api/v1/billing/instapay/claims', claimBody([
        'email' => "payer{$n}@example.test", 'reference' => sprintf('PERFREF%07d', $n), 'screenshot' => $receipt,
    ]))->assertStatus(202);

    $submit(0);
    $claims = [];
    foreach (range(1, 40) as $n) {
        $start = hrtime(true);
        $submit($n);
        $claims[] = (hrtime(true) - $start) / 1e6;
    }

    person('ada@example.test');
    $credential = signInAs('ada@example.test')->json('data.credential');
    $typed = [];
    foreach (range(0, 40) as $n) {
        $typed[$n] = RedeemCode::generate()->display();
        $claim = PaymentClaim::factory()->forAddress('ada@example.test')->approved()->create();
        RedeemCodeRow::factory()->forCode($typed[$n])->state(['claim_id' => $claim->id])->create();
    }

    $redeem = fn (int $n) => test()->withHeaders([...asCredential($credential), 'Idempotency-Key' => sprintf('perf-redeem-%010d', $n)])
        ->postJson('/api/v1/billing/redeem', ['code' => $typed[$n]])->assertOk();

    $redeem(0);
    $redemptions = [];
    foreach (range(1, 40) as $n) {
        $start = hrtime(true);
        $redeem($n);
        $redemptions[] = (hrtime(true) - $start) / 1e6;
    }

    fwrite(STDOUT, sprintf("\nSC-007 (ms, p95): claim with 3 MiB receipt %.1f, redemption %.1f\n", instapayPercentile95($claims), instapayPercentile95($redemptions)));

    expect(instapayPercentile95($claims))->toBeLessThan(800)
        ->and(instapayPercentile95($redemptions))->toBeLessThan(300);
})->skip(fn () => getenv('INSTAPAY_PERF') !== '1', 'Set INSTAPAY_PERF=1 to measure SC-007.');
