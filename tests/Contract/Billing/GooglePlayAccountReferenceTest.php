<?php

/*
 * GET /billing/google/account-reference against docs/openapi/paths/google-play.yaml (spec 024).
 */

beforeEach(function () {
    [$this->user, $this->credential] = playBuyer();
});

test('200: the caller\'s own reference, 64 lowercase hex, an empty meta object', function () {
    $response = playCall($this->credential, 'get', '/api/v1/billing/google/account-reference')->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.obfuscated_account_id'))->toMatch('/^[0-9a-f]{64}$/')->and($response->json('meta'))->toBe([])
        ->and(array_keys($response->json('data')))->toBe(['obfuscated_account_id']);
});

test('401: it needs a credential', function () {
    $this->flushHeaders();
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/billing/google/account-reference')->assertValidResponse(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('406 and 426: the shared refusals', function () {
    $uri = '/api/v1/billing/google/account-reference';

    $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Accept' => 'text/html']))->assertStatus(406);
    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->getJson($uri)->assertValidResponse(426);
});

test('it never answers with another account\'s reference', function () {
    [, $other] = playBuyer('bob@example.test');

    $mine = playCall($this->credential, 'get', '/api/v1/billing/google/account-reference')->json('data.obfuscated_account_id');
    $theirs = playCall($other, 'get', '/api/v1/billing/google/account-reference')->json('data.obfuscated_account_id');

    expect($mine)->not->toBe($theirs);
});
