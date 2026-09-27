<?php

use App\Modules\Wallets\Application\TransactionQueryService;

/*
 * GET /me and PUT /me/profile (spec 008).
 */

beforeEach(function () {
    person();
    $this->credential = signInAs()->json('data.credential');
});

test('GET /me returns the account and the profile with its defaults', function () {
    $response = $this->withHeaders(asCredential($this->credential))->get('/api/v1/me')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data.account.email'))->toBe('ada@example.test')
        ->and($response->json('data.profile'))->toMatchArray([
            'locale' => 'en',
            'timezone' => 'Africa/Cairo',
            'default_currency' => 'EGP',
            'default_currency_locked' => false,
            'budget_period_start_day' => 1,
            'budget_period_start_day_from' => null,
            'version' => 1,
        ]);
});

test('GET /me needs a credential', function () {
    $this->withHeaders(jsonHeaders())->get('/api/v1/me')
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('a valid save is 200 with the new profile', function () {
    saveProfile($this->credential, profileBody(['display_name' => 'Ada', 'locale' => 'ar', 'default_currency' => 'USD', 'budget_period_start_day' => 25]))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.default_currency', 'USD');
});

test('an identical replay is 200 with nothing moved', function () {
    saveProfile($this->credential, profileBody(['display_name' => 'Ada']))->assertOk();

    saveProfile($this->credential, profileBody(['display_name' => 'Ada']))
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);
});

test('a stale version is VERSION_CONFLICT carrying the current profile', function () {
    saveProfile($this->credential, profileBody(['display_name' => 'Ada']))->assertOk();

    saveProfile($this->credential, profileBody(['display_name' => 'Grace'], version: 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.version', 2)
        ->assertJsonPath('current.display_name', 'Ada');
});

test('invalid values are refused with their field codes', function (array $changes, string $pointer, string $code) {
    $response = saveProfile($this->credential, [...profileBody(['display_name' => 'x']), ...$changes]);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    if ($code !== 'UNKNOWN_FIELD' && $code !== 'REQUIRED') {
        $response->assertValidResponse(422);
    }
    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code);
})->with([
    'locale' => [['locale' => 'fr'], '/locale', 'INVALID_ENUM_VALUE'],
    'timezone' => [['timezone' => 'Mars/Base'], '/timezone', 'INVALID_ENUM_VALUE'],
    'lower-case currency' => [['default_currency' => 'egp'], '/default_currency', 'INVALID_CURRENCY'],
    'invented currency' => [['default_currency' => 'ABC'], '/default_currency', 'INVALID_CURRENCY'],
    'start day 29' => [['budget_period_start_day' => 29], '/budget_period_start_day', 'OUT_OF_RANGE'],
    'long name' => [['display_name' => str_repeat('a', 101)], '/display_name', 'OUT_OF_RANGE'],
    'server-owned member' => [['change_seq' => 5], '/change_seq', 'UNKNOWN_FIELD'],
]);

test('an omitted member is REQUIRED', function () {
    $body = profileBody(['display_name' => 'x']);
    unset($body['timezone']);

    $response = saveProfile($this->credential, $body)->assertStatus(422);
    expect(collect($response->json('errors'))->firstWhere('pointer', '/timezone')['code'])->toBe('REQUIRED');
});

test('saving needs a credential', function () {
    $this->withHeaders(jsonHeaders())->putJson('/api/v1/me/profile', profileBody())
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('a locked currency change is a documented 422', function () {
    app()->instance(TransactionQueryService::class, new class implements TransactionQueryService
    {
        public function hasLiveTransactionsRecordedBy(string $userId): bool
        {
            return true;
        }
    });

    saveProfile($this->credential, profileBody(['default_currency' => 'USD']))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.code', 'IMMUTABLE_FIELD');
});
