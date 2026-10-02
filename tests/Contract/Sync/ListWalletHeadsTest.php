<?php

use Illuminate\Testing\TestResponse;

/*
 * GET /sync/wallets/heads (spec 017, US1) against docs/openapi/paths/sync.yaml.
 */

function readHeads(string $credential, string $query = ''): TestResponse
{
    return walletCall('GET', $credential, '/sync/wallets/heads'.$query);
}

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->second = ownedWallet($this->user->id);
});

test('200: an entry per wallet with its head and purge horizon, ordered by wallet id', function () {
    $response = readHeads($this->credential)->assertValidRequest()->assertValidResponse(200);

    $expected = [$this->wallet->id, $this->second->id];
    sort($expected);

    expect(array_column($response->json('data'), 'wallet_id'))->toBe($expected)
        ->and($response->json('data.0'))->toHaveKeys(['wallet_id', 'change_seq', 'purged_change_seq'])
        ->and($response->json('meta'))->toBe(['next_cursor' => null, 'has_more' => false, 'limit' => 50]);
});

test('200 with an empty list for a caller in no wallet', function () {
    person('grace@example.test');
    $grace = signInAs('grace@example.test')->json('data.credential');

    readHeads($grace)->assertValidRequest()->assertValidResponse(200)->assertJsonPath('data', []);
});

test('two pages with limit=1 and the cursor between them', function () {
    $first = readHeads($this->credential, '?limit=1')->assertValidRequest()->assertValidResponse(200);
    expect($first->json('data'))->toHaveCount(1)->and($first->json('meta.has_more'))->toBeTrue();

    $second = readHeads($this->credential, '?limit=1&cursor='.$first->json('meta.next_cursor'))->assertValidRequest()->assertValidResponse(200);

    expect($second->json('data'))->toHaveCount(1)
        ->and($second->json('data.0.wallet_id'))->not->toBe($first->json('data.0.wallet_id'))
        ->and($second->json('meta.has_more'))->toBeFalse()
        ->and($second->json('meta.next_cursor'))->toBeNull();
});

test('limit=500 is clamped to 200', function () {
    readHeads($this->credential, '?limit=500')->assertValidResponse(200)->assertJsonPath('meta.limit', 200);
});

test('400 for a filter, a sort, a bad limit and a garbage cursor', function () {
    withoutSpectatorRequestValidation();

    readHeads($this->credential, '?filter[wallet_id]='.$this->wallet->id)->assertValidResponse(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    readHeads($this->credential, '?sort=wallet_id')->assertValidResponse(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    readHeads($this->credential, '?limit=0')->assertValidResponse(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    readHeads($this->credential, '?cursor=garbage')->assertValidResponse(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('401 without a token', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/sync/wallets/heads')->assertValidResponse(401);
});

test('426 below the client floor', function () {
    config(['api.minimum_client_version.android' => '9.0.0']);

    $this->withHeaders(asCredential($this->credential))->getJson('/api/v1/sync/wallets/heads')->assertStatus(426);
});

test('an unverified caller gets 200', function () {
    [$user, $credential] = walletCaller('unverified@example.test', false);
    ownedWallet($user->id);

    readHeads($credential)->assertValidRequest()->assertValidResponse(200);
});
