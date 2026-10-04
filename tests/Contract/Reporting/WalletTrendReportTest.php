<?php

use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/reports/trend (spec 019, FR-013, FR-019): every documented status.
 */

beforeEach(function () {
    freezeClock('2026-10-04 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->uri = '/wallets/'.$this->wallet->id.'/reports/trend';
});

test('200 carries the periods and one zero-filled point per period in every series', function () {
    spend($this->user->id, $this->wallet->id, 1000, ['date' => '2026-09-10']);
    recordTransaction($this->user->id, $this->wallet->id, incomeBody(['amount_minor' => 5000, 'date' => '2026-10-02']));

    $response = reportCall($this->credential, $this->uri)->assertValidRequest()->assertValidResponse(200);
    $data = $response->json('data');

    expect($data['wallet_id'])->toBe($this->wallet->id)
        ->and($data['currency'])->toBe('EGP')
        ->and($data['periods'])->toHaveCount(6)
        ->and($data['periods'][0])->toBe(['label' => '2026-05-01', 'from' => '2026-05-01', 'to' => '2026-05-31'])
        ->and($data['expenses'][4])->toBe(['label' => '2026-09-01', 'value' => wireMoney(1000)])
        ->and($data['income'][5])->toBe(['label' => '2026-10-01', 'value' => wireMoney(5000)])
        ->and($data['net'][5]['value'])->toBe(wireMoney(5000))
        ->and($data['income'][0]['value'])->toBe(wireMoney(0))
        ->and($data['planned_income'])->toBeNull()
        ->and($data['planned_expenses'])->toBeNull()
        ->and($response->getContent())->toContain('"meta":{}');
});

test('200 for one and for twelve periods', function () {
    reportCall($this->credential, $this->uri.'?months=1')->assertValidResponse(200)->assertJsonCount(1, 'data.periods');
    reportCall($this->credential, $this->uri.'?months=12&date=2026-03-15')->assertValidResponse(200)->assertJsonCount(12, 'data.periods');
});

test('from and to on the trend are ignored', function () {
    reportCall($this->credential, $this->uri.'?from=2026-09-01&to=nope')->assertValidResponse(200)->assertJsonCount(6, 'data.periods');
});

test('400 for months outside 1 to 12, not an integer, and a malformed date', function (string $query) {
    reportCall($this->credential, $this->uri.'?'.$query)->assertValidResponse(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
})->with(['months=0', 'months=13', 'months=x', 'date=2026-9-1', 'months=6&include_planned=maybe']);

test('404 and 422', function () {
    reportCall($this->credential, '/wallets/'.Str::uuid7().'/reports/trend')->assertValidResponse(404);
    reportCall($this->credential, '/wallets/x/reports/trend')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('401, 406, 426 and 405 with Allow', function () {
    assertReportSharedRefusals($this->credential, $this->uri);
});
