<?php

/*
 * POST /auth/google is reserved and switched off in MVP (spec 007, FR-080).
 */

test('while switched off, Google Sign-In does not exist', function () {
    expect(config('identity.google.enabled'))->toBeFalse();

    // No route exists, so the API fallback answers; Spectator matches by
    // route, not path, and cannot validate a fallback response.
    withoutSpectatorRequestValidation();
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/google', ['id_token' => 'x', 'device' => deviceBody()])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_FOUND');
});
