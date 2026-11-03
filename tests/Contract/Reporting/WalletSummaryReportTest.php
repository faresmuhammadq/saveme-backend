<?php

use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/reports/summary (spec 019, FR-009, FR-019): every
 * documented status, validated against docs/openapi/paths/reports.yaml.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    ['cash' => $this->cash, 'bank' => $this->bank] = reportScenario($this->user->id);
    $this->uri = '/wallets/'.$this->cash->id.'/reports/summary';
});

test('200 carries every documented member, planned null and meta an empty object', function () {
    $response = reportCall($this->credential, $this->uri)->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toBe([
        'wallet_id' => $this->cash->id,
        'range' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        'currency' => 'EGP',
        'income' => wireMoney(1000000),
        'expenses' => wireMoney(350000),
        'net' => wireMoney(650000),
        'transfers_in' => wireMoney(0),
        'transfers_out' => wireMoney(200000),
        'adjustments_in' => wireMoney(0),
        'adjustments_out' => wireMoney(0),
        'balance_start' => wireMoney(80000),
        'balance_end' => wireMoney(530000),
        'planned' => null,
    ])->and($response->getContent())->toContain('"meta":{}');
});

test('200 with include_planned carries the planned sums', function () {
    createRecurringRule($this->user->id, $this->cash->id, recurringRuleBody(['start_date' => '2026-10-20']));
    Occurrence::query()->update(['date' => '2026-10-20']);

    $data = reportCall($this->credential, $this->uri.'?include_planned=true')->assertValidRequest()->assertValidResponse(200)->json('data');

    expect($data['planned'])->toBe([
        'income' => wireMoney(0), 'expenses' => wireMoney(450000), 'transfers_in' => wireMoney(0), 'transfers_out' => wireMoney(0),
    ])->and($data['expenses'])->toBe(wireMoney(350000));
});

test('200 over an explicit range for a credit-card wallet', function () {
    $card = ownedWallet($this->user->id, ['type' => 'credit_card', 'opening_balance_minor' => -300000]);
    spend($this->user->id, $card->id, 420000, ['date' => '2026-09-10']);

    $data = reportCall($this->credential, '/wallets/'.$card->id.'/reports/summary?from=2026-09-01&to=2026-09-30')->assertValidRequest()->assertValidResponse(200)->json('data');

    expect($data['range'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and($data['balance_start'])->toBe(wireMoney(-300000))
        ->and($data['balance_end'])->toBe(wireMoney(-720000));
});

test('a currency parameter on a wallet report is ignored', function () {
    reportCall($this->credential, $this->uri.'?currency=USD')->assertValidResponse(200)->assertJsonPath('data.currency', 'EGP');
});

test('400 INVALID_QUERY_PARAMETER for each refusal that applies to a wallet summary', function (string $query) {
    reportCall($this->credential, $this->uri.'?'.$query)
        ->assertValidResponse(400)
        ->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
})->with([
    'malformed date' => 'date=2026-9-1',
    'from alone' => 'from=2026-09-01',
    'to alone' => 'to=2026-09-30',
    'date with from and to' => 'date=2026-09-05&from=2026-09-01&to=2026-09-30',
    'from after to' => 'from=2026-10-02&to=2026-10-01',
    'a 367 day span' => 'from=2026-01-01&to=2027-01-02',
    'include_planned yes' => 'include_planned=yes',
]);

test('404 for an unknown wallet and 422 INVALID_ID for a malformed id', function () {
    reportCall($this->credential, '/wallets/'.Str::uuid7().'/reports/summary')->assertValidResponse(404);
    reportCall($this->credential, '/wallets/nope/reports/summary')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('401, 406, 426 and 405 with Allow', function () {
    assertReportSharedRefusals($this->credential, $this->uri);
});
