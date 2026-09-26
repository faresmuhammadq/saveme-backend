<?php

/*
 * Plaintext is refused, not redirected (security baseline, Transport).
 */

beforeEach(function () {
    registerApiProbes();
    config(['api.require_tls' => true]);
});

test('a plaintext request is refused with INSECURE_TRANSPORT', function () {
    $this->withHeaders(jsonHeaders())->get('http://localhost/api/v1/_probe')
        ->assertStatus(400)
        ->assertJsonPath('code', 'INSECURE_TRANSPORT')
        ->assertHeaderMissing('Location');
});

test('a secure response carries a one-year HSTS header', function () {
    $this->withHeaders(jsonHeaders())->get('https://localhost/api/v1/_probe')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
