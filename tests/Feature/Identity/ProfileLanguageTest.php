<?php

use App\Modules\Identity\Application\Mail\PasswordChangedMail;
use Illuminate\Support\Facades\Mail;

/*
 * The profile's language is the default when a request names none
 * (spec 008, FR-008, R-5).
 */

beforeEach(function () {
    person();
    $this->credential = signInAs()->json('data.credential');
    saveProfile($this->credential, profileBody(['locale' => 'ar']))->assertOk();
});

test('a signed-in request without Accept-Language is answered in the profile language', function () {
    // Symfony's test requests send a default Accept-Language; an empty one stands in for none.
    $response = $this->withHeaders([...asCredential($this->credential), 'Accept-Language' => ''])->deleteJson('/api/v1/auth/sessions/abc');

    $response->assertStatus(422)->assertHeader('Content-Language', 'ar');
    expect($response->json('detail'))->toBe(trans('problems.INVALID_ID', [], 'ar'));
});

test('an explicit Accept-Language still wins', function () {
    $this->withHeaders([...asCredential($this->credential), 'Accept-Language' => 'en'])->get('/api/v1/me')
        ->assertHeader('Content-Language', 'en');
});

test('a request without a credential falls back to English', function () {
    $this->withHeaders(jsonHeaders(['Accept-Language' => '']))->get('/api/v1/me')->assertHeader('Content-Language', 'en');
});

test('mail follows the profile language', function () {
    Mail::fake();

    changePassword($this->credential, ['current_password' => 'correct horse', 'new_password' => 'battery staple'])->assertNoContent();

    Mail::assertQueued(PasswordChangedMail::class, fn (PasswordChangedMail $mail): bool => $mail->locale === 'ar');
});
