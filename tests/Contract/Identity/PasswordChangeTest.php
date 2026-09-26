<?php

use Illuminate\Support\Facades\Mail;

/*
 * POST /auth/change-password (spec 007, US7).
 */

beforeEach(function () {
    Mail::fake();
    person();
    $this->credential = signInAs()->json('data.credential');
});

test('the right current password and a valid new one is 204', function () {
    changePassword($this->credential, ['current_password' => 'correct horse', 'new_password' => 'battery staple'])
        ->assertValidRequest()
        ->assertValidResponse(204);
});

test('a wrong current password is INVALID_CREDENTIALS', function () {
    changePassword($this->credential, ['current_password' => 'nope', 'new_password' => 'battery staple'])
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

test('without a credential it is UNAUTHENTICATED', function () {
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/change-password', ['current_password' => 'correct horse', 'new_password' => 'battery staple'])
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('a short password, or the same one again, is a validation failure', function (string $new) {
    changePassword($this->credential, ['current_password' => 'correct horse', 'new_password' => $new])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/new_password');
})->with(['short', 'correct horse']);

test('a locked account is 423', function () {
    foreach (range(1, 5) as $attempt) {
        changePassword($this->credential, ['current_password' => 'nope', 'new_password' => 'battery staple']);
    }

    changePassword($this->credential, ['current_password' => 'correct horse', 'new_password' => 'battery staple'])
        ->assertValidResponse(423)
        ->assertJsonPath('code', 'ACCOUNT_LOCKED');
});
