<?php

use Illuminate\Support\Facades\Mail;

/*
 * POST/DELETE /account/deletion-request (spec 007, US6).
 */

beforeEach(function () {
    Mail::fake();
    person();
    $this->credential = signInAs()->json('data.credential');
});

test('a request with the password starts the grace period; a repeat keeps the date', function () {
    $first = $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/account/deletion-request', ['password' => 'correct horse'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.state', 'pending_deletion');
    $this->flushHeaders();
    $this->travel(5)->days();

    $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/account/deletion-request', ['password' => 'correct horse'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.deletion_scheduled_for', $first->json('data.deletion_scheduled_for'));
});

test('a wrong password is refused and changes nothing', function () {
    $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/account/deletion-request', ['password' => 'not it'])
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

test('a locked account is refused with 423', function () {
    foreach (range(1, 5) as $attempt) {
        $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/account/deletion-request', ['password' => 'not it']);
        $this->flushHeaders();
    }

    $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/account/deletion-request', ['password' => 'correct horse'])
        ->assertValidResponse(423)
        ->assertJsonPath('code', 'ACCOUNT_LOCKED');
});

test('a missing password is a validation failure; no credential is 401', function () {
    $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/account/deletion-request', [])->assertValidResponse(422);
    $this->flushHeaders();
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/account/deletion-request', ['password' => 'x'])
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('cancelling is 204, pending or not', function () {
    $this->withHeaders(asCredential($this->credential))->deleteJson('/api/v1/account/deletion-request')->assertValidResponse(204);
    $this->flushHeaders();
    $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/account/deletion-request', ['password' => 'correct horse']);
    $this->flushHeaders();
    $this->withHeaders(asCredential($this->credential))->deleteJson('/api/v1/account/deletion-request')->assertValidResponse(204);
    $this->flushHeaders();
    $this->withHeaders(jsonHeaders())->deleteJson('/api/v1/account/deletion-request')->assertValidResponse(401);
});
