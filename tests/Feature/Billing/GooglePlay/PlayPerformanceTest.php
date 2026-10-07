<?php

use Illuminate\Support\Facades\Queue;
use Tests\Support\PlayPushSigner;

/*
 * SC-009 (spec 024): verify p95 under 3 s (bounded by the adapter's 5 and 10 s
 * timeouts only while Google is healthy, so measured here against the fake: the
 * server's own cost), the webhook p95 under 0.5 s and the account reference p95
 * under 300 ms, after warm-up, on the development stack with debugging
 * extensions off. It runs only on request:
 * `PLAY_PERF=1 XDEBUG_MODE=off dev exec -- vendor/bin/pest tests/Feature/Billing/GooglePlay/PlayPerformanceTest.php`.
 */

function playPercentile95(array $milliseconds): float
{
    sort($milliseconds);

    return $milliseconds[(int) ceil(0.95 * count($milliseconds)) - 1];
}

test('verify, the webhook and the account reference stay inside the targets', function () {
    config(['billing.google_play.throttle.verify_per_hour' => 100000]);
    Queue::fake();
    mapPlayProducts();
    [$user, $credential] = playBuyer();
    $signer = PlayPushSigner::make();
    $signer->serveCertificates();

    $verify = function (int $n) use ($credential) {
        $token = playToken("perf-{$n}");
        scriptPlay($token);

        return [$token, fn () => playCall($credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $token, 'product_id' => 'plus_monthly'], sprintf('perf-verify-%010d', $n))->assertOk()];
    };

    [, $warm] = $verify(0);
    $warm();
    $verifies = [];
    foreach (range(1, 40) as $n) {
        [, $call] = $verify($n);
        $start = hrtime(true);
        $call();
        $verifies[] = (hrtime(true) - $start) / 1e6;
    }

    $hook = fn (int $n) => postPush(pubsubPush(subscriptionNotification(playToken("hook-{$n}")), "perf-msg-{$n}"), $signer->token())->assertNoContent();
    $hook(0);
    $webhooks = [];
    foreach (range(1, 40) as $n) {
        $start = hrtime(true);
        $hook($n);
        $webhooks[] = (hrtime(true) - $start) / 1e6;
    }

    $references = [];
    foreach (range(1, 40) as $ignored) {
        $start = hrtime(true);
        playCall($credential, 'get', '/api/v1/billing/google/account-reference')->assertOk();
        $references[] = (hrtime(true) - $start) / 1e6;
    }

    fwrite(STDOUT, sprintf("\nSC-009 (ms, p95): verify %.1f, webhook %.1f, account reference %.1f\n", playPercentile95($verifies), playPercentile95($webhooks), playPercentile95($references)));

    expect(playPercentile95($verifies))->toBeLessThan(3000)
        ->and(playPercentile95($webhooks))->toBeLessThan(500)
        ->and(playPercentile95($references))->toBeLessThan(300);
})->skip(fn () => ! getenv('PLAY_PERF'), 'Runs only on request: PLAY_PERF=1.');
