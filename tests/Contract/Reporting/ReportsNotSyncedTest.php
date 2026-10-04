<?php

use Illuminate\Support\Str;

/*
 * Reports are derived views, never syncable (spec 019, FR-018): they appear
 * on no change feed and are not a batch item type.
 */

test('no report row appears on the user feed or a wallet feed, however much is recorded', function () {
    freezeClock('2026-10-15 10:00:00');
    [$user, $credential] = walletCaller();
    ['cash' => $cash] = reportScenario($user->id);
    reportCall($credential, '/wallets/'.$cash->id.'/reports/summary')->assertOk();
    reportCall($credential, '/reports/summary')->assertOk();

    $userRows = readFeedFully($credential, 'user')['rows'];
    $walletRows = readFeedFully($credential, $cash->id)['rows'];

    expect(collect([...$userRows, ...$walletRows])->pluck('type')->unique()->values()->all())->not->toContain('report')
        ->and(collect([...$userRows, ...$walletRows])->pluck('type')->filter(fn (string $type): bool => str_contains($type, 'report'))->all())->toBe([]);
});

test('a batch item of type report is refused as an unknown type', function () {
    [, $credential] = walletCaller();

    $response = postBatch($credential, [syncItem('report', 'upsert', (string) Str::uuid7(), ['income' => 1])]);

    expect($response->status())->toBe(422)
        ->and($response->json('code'))->toBe('VALIDATION_FAILED');
});
