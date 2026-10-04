<?php

/*
 * The query refusals of FR-019 across all six operations: each malformed or
 * contradictory parameter is 400 INVALID_QUERY_PARAMETER with a plain
 * problem body (no `errors[]`), and nothing malformed is clamped.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->paths = [
        '/wallets/'.$this->wallet->id.'/reports/summary',
        '/wallets/'.$this->wallet->id.'/reports/by-category',
        '/wallets/'.$this->wallet->id.'/reports/trend',
        '/reports/summary',
        '/reports/by-category',
        '/reports/trend',
    ];
});

test('a refusal is a plain problem on every operation that declares the parameter', function (string $query, array $kinds) {
    foreach ($this->paths as $path) {
        $kind = str_ends_with($path, 'trend') ? 'trend' : 'range';

        if (! in_array($kind, $kinds, true)) {
            continue;
        }

        $response = reportCall($this->credential, $path.'?'.$query)->assertValidResponse(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');

        expect($response->json('errors'))->toBeNull();
    }
})->with([
    'a malformed date' => ['date=2026-02-30', ['range', 'trend']],
    'a date out of bounds' => ['date=3000-01-01', ['range', 'trend']],
    'a date as an array' => ['date[]=2026-10-01', ['range', 'trend']],
    'from without to' => ['from=2026-09-01', ['range']],
    'to without from' => ['to=2026-09-30', ['range']],
    'date with from and to' => ['date=2026-09-05&from=2026-09-01&to=2026-09-30', ['range']],
    'from after to' => ['from=2026-10-02&to=2026-10-01', ['range']],
    'a 367 day span' => ['from=2026-01-01&to=2027-01-02', ['range']],
    'a range below 1900' => ['from=1899-12-01&to=1899-12-31', ['range']],
    'months zero' => ['months=0', ['trend']],
    'months thirteen' => ['months=13', ['trend']],
    'months not an integer' => ['months=1.5', ['trend']],
    'include_planned yes' => ['include_planned=yes', ['range', 'trend']],
    'include_planned upper case' => ['include_planned=TRUE', ['range', 'trend']],
]);

test('the boundaries are accepted on every operation', function (string $query, array $kinds) {
    foreach ($this->paths as $path) {
        $kind = str_ends_with($path, 'trend') ? 'trend' : 'range';

        if (in_array($kind, $kinds, true)) {
            reportCall($this->credential, $path.'?'.$query)->assertValidResponse(200);
        }
    }
})->with([
    'a 366 day span' => ['from=2024-01-01&to=2024-12-31', ['range']],
    'the first date' => ['date=1900-01-01', ['range', 'trend']],
    'the last date' => ['date=2999-12-31', ['range', 'trend']],
    'one month' => ['months=1', ['trend']],
    'twelve months' => ['months=12', ['trend']],
    'empty values are absent' => ['date=&from=&to=&months=&include_planned=&currency=', ['range', 'trend']],
    'undeclared parameters are ignored' => ['limit=5&sort=name&filter[x]=y', ['range', 'trend']],
]);
