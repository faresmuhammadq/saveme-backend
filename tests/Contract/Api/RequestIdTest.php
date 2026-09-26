<?php

/*
 * Request and correlation identifiers (docs/api-conventions.md).
 */

beforeEach(fn () => registerApiProbes());

test('every response carries a UUIDv7 request id, success or failure', function () {
    expect($this->withHeaders(jsonHeaders())->get('/api/v1/_probe')->headers->get('X-Request-Id'))->toBeUuidV7()
        ->and($this->withHeaders(jsonHeaders())->get('/api/v1/nope')->headers->get('X-Request-Id'))->toBeUuidV7()
        ->and($this->get('/api/v1/_probe')->headers->get('X-Request-Id'))->toBeUuidV7();
});

test('a valid correlation id is echoed', function () {
    $this->withHeaders(jsonHeaders(['X-Correlation-Id' => 'app:1234.abc']))->get('/api/v1/_probe')
        ->assertOk()
        ->assertHeader('X-Correlation-Id', 'app:1234.abc');
});

test('an invalid correlation id is dropped silently', function () {
    $this->withHeaders(jsonHeaders(['X-Correlation-Id' => 'bad id!']))->get('/api/v1/_probe')
        ->assertOk()
        ->assertHeaderMissing('X-Correlation-Id');
});
