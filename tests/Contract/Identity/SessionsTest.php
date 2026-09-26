<?php

/*
 * GET/DELETE /auth/sessions, DELETE /auth/sessions/{id}, POST /auth/logout
 * (spec 007, US3).
 */

beforeEach(function () {
    person();
    person('other@example.test');
    $this->phone = signInAs(device: deviceBody(['name' => 'Phone']))->json('data');
    $this->tablet = signInAs(device: deviceBody(['name' => 'Tablet']))->json('data');
    signInAs('other@example.test');
});

test('the list shows only the caller\'s sessions, the current one marked, nothing further', function () {
    $response = $this->withHeaders(asCredential($this->phone['credential']))->get('/api/v1/auth/sessions')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.limit', 50);

    $sessions = collect($response->json('data'));
    expect($sessions)->toHaveCount(2)
        ->and($sessions->where('current', true)->pluck('device_name')->all())->toBe(['Phone'])
        ->and($sessions->first())->not->toHaveKeys(['device_id', 'token', 'origin']);
});

test('the list pages with an opaque cursor, and can filter and sort', function () {
    $first = $this->withHeaders(asCredential($this->phone['credential']))->get('/api/v1/auth/sessions?limit=1&sort=-last_used_at')
        ->assertValidResponse(200)
        ->assertJsonPath('meta.has_more', true);
    $this->flushHeaders();

    $second = $this->withHeaders(asCredential($this->phone['credential']))->get('/api/v1/auth/sessions?limit=1&sort=-last_used_at&cursor='.$first->json('meta.next_cursor'))
        ->assertValidResponse(200)
        ->assertJsonPath('meta.has_more', false);
    $this->flushHeaders();

    expect($second->json('data.0.id'))->not->toBe($first->json('data.0.id'));

    withoutSpectatorRequestValidation();
    $this->withHeaders(asCredential($this->phone['credential']))->get('/api/v1/auth/sessions?filter[state]=eq:revoked')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('unsupported collection parameters are refused', function (string $query, string $code) {
    if (str_starts_with($query, 'filter')) {
        withoutSpectatorRequestValidation();
    }

    $response = $this->withHeaders(asCredential($this->phone['credential']))->get('/api/v1/auth/sessions?'.$query)
        ->assertStatus(400)
        ->assertJsonPath('code', $code);

    if (! str_starts_with($query, 'filter')) {
        $response->assertValidResponse(400);
    }
})->with([
    ['filter[device_name]=Phone', 'FILTER_FIELD_NOT_SUPPORTED'],
    ['filter[state]=ne:active', 'FILTER_OPERATOR_NOT_SUPPORTED'],
    ['sort=device_name', 'SORT_FIELD_NOT_SUPPORTED'],
    ['limit=0', 'INVALID_QUERY_PARAMETER'],
    ['cursor=forged', 'INVALID_CURSOR'],
]);

test('ending a session is 204, and again 204 once it has ended', function () {
    $tablet = $this->tablet['session']['id'];

    $this->withHeaders(asCredential($this->phone['credential']))->deleteJson("/api/v1/auth/sessions/{$tablet}")->assertValidResponse(204);
    $this->flushHeaders();
    $this->withHeaders(asCredential($this->phone['credential']))->deleteJson("/api/v1/auth/sessions/{$tablet}")->assertValidResponse(204);
});

test("ending another person's session is not found; a malformed id is INVALID_ID", function () {
    $theirs = signInAs('other@example.test')->json('data.session.id');

    $this->withHeaders(asCredential($this->phone['credential']))->deleteJson("/api/v1/auth/sessions/{$theirs}")
        ->assertValidResponse(404)
        ->assertJsonPath('code', 'NOT_FOUND');
    $this->flushHeaders();
    $this->withHeaders(asCredential($this->phone['credential']))->deleteJson('/api/v1/auth/sessions/abc')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_ID');
});

test('ending all others and signing out are 204', function () {
    $this->withHeaders(asCredential($this->phone['credential']))->deleteJson('/api/v1/auth/sessions')->assertValidResponse(204);
    $this->flushHeaders();
    $this->withHeaders(asCredential($this->phone['credential']))->postJson('/api/v1/auth/logout')->assertValidResponse(204);
});

test('every session operation needs a credential', function (string $method, string $uri) {
    $this->flushHeaders();
    $this->withHeaders(jsonHeaders())->json($method, $uri)
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
})->with([
    ['GET', '/api/v1/auth/sessions'],
    ['DELETE', '/api/v1/auth/sessions'],
    ['DELETE', '/api/v1/auth/sessions/0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70'],
    ['POST', '/api/v1/auth/logout'],
]);
