<?php

/*
 * Every failure is an RFC 9457 problem body (docs/api-conventions.md, Errors).
 */

beforeEach(fn () => registerApiProbes());

test('a problem carries type, title, status, code, detail and the request id', function () {
    $response = $this->withHeaders(jsonHeaders())->get('/api/v1/nope');

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('title', 'Not found')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('request_id', $response->headers->get('X-Request-Id'));

    expect($response->json('type'))->toEndWith('/problems/not-found')
        ->and($response->json('detail'))->toBeString()->not->toBeEmpty();
});

test('a wrong method is 405 with Allow', function () {
    $this->withHeaders(jsonHeaders())->delete('/api/v1/_probe')
        ->assertStatus(405)
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')
        ->assertHeader('Allow');
});

test('validation failures list a pointer and a field code per input', function () {
    $response = $this->withHeaders(jsonHeaders())->postJson('/api/v1/_probe', ['name' => 'far too long a name', 'device' => ['platform' => 'ios']]);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    expect($response->json('errors'))->toContain(
        ['pointer' => '/name', 'code' => 'OUT_OF_RANGE', 'detail' => __('problems.fields.OUT_OF_RANGE')],
        ['pointer' => '/device/platform', 'code' => 'INVALID_ENUM_VALUE', 'detail' => __('problems.fields.INVALID_ENUM_VALUE')],
    );
});

test('an unknown member is refused, not ignored', function () {
    $response = $this->withHeaders(jsonHeaders())->postJson('/api/v1/_probe', ['name' => 'ok', 'nickname' => 'x', 'device' => ['platform' => 'android', 'model' => 'x']]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->where('code', 'UNKNOWN_FIELD')->pluck('pointer')->all())
        ->toEqualCanonicalizing(['/nickname', '/device/model']);
});

test('an unexpected failure is INTERNAL_ERROR and leaks nothing', function () {
    $response = $this->withHeaders(jsonHeaders())->get('/api/v1/_boom');

    $response->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
    expect($response->getContent())->not->toContain('secret stack detail');
});
