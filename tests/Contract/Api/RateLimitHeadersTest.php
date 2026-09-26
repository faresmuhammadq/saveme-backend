<?php

/*
 * RateLimit-* headers, never X-RateLimit-* (docs/api-conventions.md).
 */

beforeEach(fn () => registerApiProbes());

test('a throttled route reports its standing without the X- prefix', function () {
    $response = $this->withHeaders(jsonHeaders())->get('/api/v1/_throttled');

    $response->assertOk()
        ->assertHeader('RateLimit-Limit', '2')
        ->assertHeader('RateLimit-Remaining', '1')
        ->assertHeaderMissing('X-RateLimit-Limit')
        ->assertHeaderMissing('X-RateLimit-Remaining');
});

test('a spent allowance is 429 RATE_LIMITED with Retry-After and RateLimit-Reset', function () {
    $this->withHeaders(jsonHeaders())->get('/api/v1/_throttled');
    $this->withHeaders(jsonHeaders())->get('/api/v1/_throttled');

    $response = $this->withHeaders(jsonHeaders())->get('/api/v1/_throttled');

    $response->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED')
        ->assertHeader('Retry-After')
        ->assertHeader('RateLimit-Reset')
        ->assertHeaderMissing('X-RateLimit-Limit');
    expect($response->json('retry_after_seconds'))->toBeInt();
});
