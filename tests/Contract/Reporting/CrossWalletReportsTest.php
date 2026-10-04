<?php

/*
 * GET /reports/summary, /reports/by-category and /reports/trend (spec 019,
 * FR-006 to FR-008, FR-019): every documented status for the three.
 */

const CROSS_WALLET_PATHS = ['/reports/summary', '/reports/by-category', '/reports/trend'];

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->cash = ownedWallet($this->user->id, ['opening_balance_minor' => 100000]);
    $this->bank = ownedWallet($this->user->id);
    $this->usd = ownedWallet($this->user->id, ['currency' => 'USD']);
    recordTransaction($this->user->id, $this->cash->id, incomeBody(['amount_minor' => 1000000, 'date' => '2026-10-02']));
    spend($this->user->id, $this->bank->id, 40000, ['date' => '2026-10-03']);
    recordTransaction($this->user->id, $this->cash->id, transferBody($this->bank->id, ['amount_minor' => 10000, 'date' => '2026-10-04']));
});

test('200 on the three operations carries the figures and meta.excluded_wallets', function () {
    $summary = reportCall($this->credential, '/reports/summary')->assertValidRequest()->assertValidResponse(200);

    expect($summary->json('data.wallet_id'))->toBeNull()
        ->and($summary->json('data.income'))->toBe(wireMoney(1000000))
        ->and($summary->json('data.expenses'))->toBe(wireMoney(40000))
        ->and($summary->json('data.transfers_out'))->toBe(wireMoney(0))
        ->and($summary->json('data.balance_end'))->toBe(wireMoney(1060000))
        ->and($summary->json('meta'))->toBe(['excluded_wallets' => [['wallet_id' => $this->usd->id, 'currency' => 'USD']]]);

    reportCall($this->credential, '/reports/by-category')->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('data.wallet_id', null)
        ->assertJsonPath('meta.excluded_wallets.0.currency', 'USD')
        ->assertJsonPath('data.expenses.total.amount_minor', 40000);

    reportCall($this->credential, '/reports/trend')->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('data.wallet_id', null)
        ->assertJsonCount(6, 'data.periods')
        ->assertJsonPath('data.expenses.5.value.amount_minor', 40000);
});

test('200 with nothing excluded has an empty list, not an empty object', function () {
    [, $credential] = walletCaller('solo@example.test');

    $response = reportCall($credential, '/reports/summary')->assertValidResponse(200);

    expect($response->getContent())->toContain('"meta":{"excluded_wallets":[]}')
        ->and($response->json('data.balance_end'))->toBe(wireMoney(0));
});

test('the currency parameter selects the covered wallets', function () {
    $data = reportCall($this->credential, '/reports/summary?currency=USD')->assertValidRequest()->assertValidResponse(200);

    expect($data->json('data.currency'))->toBe('USD')->and($data->json('meta.excluded_wallets'))->toHaveCount(2);
});

test('400 for the currency, range and months refusals', function (string $path, string $query) {
    reportCall($this->credential, $path.'?'.$query)->assertValidResponse(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
})->with([
    'lower case currency' => ['/reports/summary', 'currency=egp'],
    'long currency' => ['/reports/by-category', 'currency=EURO'],
    'currency on the trend' => ['/reports/trend', 'currency=eg'],
    'from alone' => ['/reports/summary', 'from=2026-09-01'],
    'date with from and to' => ['/reports/by-category', 'date=2026-09-05&from=2026-09-01&to=2026-09-30'],
    'a 367 day span' => ['/reports/summary', 'from=2026-01-01&to=2027-01-02'],
    'months thirteen' => ['/reports/trend', 'months=13'],
    'months zero' => ['/reports/trend', 'months=0'],
    'include_planned yes' => ['/reports/summary', 'include_planned=yes'],
]);

test('401, 406, 426 and 405 with Allow on each operation', function (string $path) {
    assertReportSharedRefusals($this->credential, $path);
})->with(CROSS_WALLET_PATHS);

test('an unverified person reads and nothing is gated by a plan', function () {
    [, $credential] = walletCaller('unverified@example.test', verified: false);

    foreach (CROSS_WALLET_PATHS as $path) {
        reportCall($credential, $path.'?include_planned=true')->assertValidResponse(200);
    }
});
