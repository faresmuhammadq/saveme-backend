<?php

/*
| Two-step operator sign-in (spec 018, US1; FR-010 to FR-016, FR-092).
*/

use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

function submitPassword(string $email, string $password): TestResponse
{
    return test()->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
}

function submitCode(string $code): TestResponse
{
    return test()->post(route('admin.sign-in.code.submit'), ['one_time_code' => $code]);
}

it('sends a signed-out visitor to step 1 and back to the page they asked for', function () {
    ['email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    $this->get('/admin/security')->assertRedirect(route('admin.sign-in'));

    submitPassword($email, $password)->assertRedirect(route('admin.sign-in.code'));
    submitCode(currentCode($secret))->assertRedirect(url('/admin/security'));
});

it('shows step 2 after a correct password and reaches no guarded page yet', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    submitPassword($email, $password)->assertRedirect(route('admin.sign-in.code'));

    $this->get(route('admin.sign-in.code'))
        ->assertOk()
        ->assertSee('Step 2 of 2 · Second factor')
        ->assertSee('Enter your 6-digit code')
        ->assertSee($email)
        ->assertSee('Not you?')
        ->assertSee('Use a recovery code')
        ->assertSee('Lost your device?');

    $this->get('/admin')->assertRedirect(route('admin.sign-in.code'));
    $this->get('/admin/security')->assertRedirect(route('admin.sign-in.code'));
    expect(app(OperatorSession::class)->qualifies())->toBe('signed_out');
});

it('signs in with the current, previous or next step code', function (int $offset) {
    ['email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    submitPassword($email, $password);
    submitCode(currentCode($secret, $offset))->assertRedirect(route('admin.overview'));

    $this->get('/admin')->assertOk()->assertSee('Overview');
})->with([-1, 0, 1]);

it('refuses a code two steps away', function (int $offset) {
    ['email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    submitPassword($email, $password);
    submitCode(currentCode($secret, $offset))->assertRedirect(route('admin.sign-in.code'));

    expect(app(OperatorSession::class)->qualifies())->toBe('signed_out');
})->with([-2, 2]);

it('keeps the operator on step 2 with the message and empty digits after a wrong code', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    submitPassword($email, $password);
    submitCode('000000')->assertRedirect(route('admin.sign-in.code'));

    $this->get(route('admin.sign-in.code'))
        ->assertOk()
        ->assertSee("That code didn't match. Try the current code, or use a recovery code.")
        ->assertDontSee('value="000000"', false);
});

it('refuses a code step already used, even from another session', function () {
    ['email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();
    $code = currentCode($secret);

    submitPassword($email, $password);
    submitCode($code)->assertRedirect(route('admin.overview'));

    app(OperatorSession::class)->end();

    submitPassword($email, $password);
    submitCode($code)->assertRedirect(route('admin.sign-in.code'));
    submitCode(currentCode($secret, -1))->assertRedirect(route('admin.sign-in.code'));
    expect(app(OperatorSession::class)->qualifies())->toBe('signed_out');
});

it('answers an unknown email and a wrong password identically, and hashes in both cases', function () {
    ['email' => $email] = makeOperator();

    submitPassword('warm-up@example.test', 'not the password'); // builds the dummy hash once

    Hash::spy();
    submitPassword($email, 'not the password')->assertRedirect(route('admin.sign-in'));
    $wrongPassword = session('admin.error');
    Hash::shouldHaveReceived('check')->once();

    submitPassword('nobody@example.test', 'not the password')->assertRedirect(route('admin.sign-in'));
    Hash::shouldHaveReceived('check')->twice();

    expect($wrongPassword)->toBe('Email or password is incorrect.')
        ->and(session('admin.error'))->toBe($wrongPassword);
});

it('discards the half-finished sign-in on "Not you?"', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    submitPassword($email, $password);
    $this->post(route('admin.sign-in.restart'))->assertRedirect(route('admin.sign-in'));

    expect(app(PendingSignIn::class)->exists())->toBeFalse();
    $this->get(route('admin.sign-in.code'))->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertOk()->assertDontSee($email);
});

it('times a half-finished sign-in out after five minutes', function () {
    ['email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    submitPassword($email, $password);
    $this->travel(6)->minutes();

    submitCode(currentCode($secret))->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('Your sign-in timed out. Start again.');
});

it('replaces the session identifier at each step', function () {
    ['email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    $before = session()->getId();
    submitPassword($email, $password);
    $afterPassword = session()->getId();
    submitCode(currentCode($secret));
    $afterCode = session()->getId();

    expect([$before, $afterPassword, $afterCode])->each->toBeString()
        ->and(count(array_unique([$before, $afterPassword, $afterCode])))->toBe(3);
});

it('rehashes the password when its parameters are below the setting', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();
    $old = $operator->password_hash;

    config(['hashing.argon.memory' => 2048]);
    Hash::forgetDrivers();
    submitPassword($email, $password)->assertRedirect(route('admin.sign-in.code'));

    $operator->refresh();
    expect($operator->password_hash)->not->toBe($old)
        ->and(Hash::check($password, $operator->password_hash))->toBeTrue();
});

it('notes the sign-in and writes exactly one entry naming the factor', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    submitPassword($email, $password);
    submitCode(currentCode($secret));

    $entries = DB::table('audit_entries')->where('event', 'sign_in_succeeded')->get();

    expect($operator->refresh()->last_signed_in_at)->not->toBeNull()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('operator')
        ->and($entries[0]->actor_id)->toBe($operator->id)
        ->and($entries[0]->subject_type)->toBe('operator')
        ->and(json_decode($entries[0]->detail, true))->toBe(['factor' => 'code', 'recovery_codes_left' => 0]);
});

it('refuses step 2 like a wrong code when the operator was deactivated after step 1', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    submitPassword($email, $password);
    $operator->forceFill(['state' => 'deactivated'])->save();

    submitCode(currentCode($secret))->assertRedirect(route('admin.sign-in.code'));
    $this->get(route('admin.sign-in.code'))->assertSee("That code didn't match.");
    expect(app(OperatorSession::class)->qualifies())->toBe('signed_out');
});

it('follows only a back-office path on the same host after sign-in', function (string $intended) {
    ['email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    $this->withSession(['admin.intended' => $intended]);
    submitPassword($email, $password);
    submitCode(currentCode($secret))->assertRedirect(route('admin.overview'));
})->with([
    'absolute' => 'https://evil.test/admin',
    'protocol-relative' => '//evil.test/admin',
    'another path' => '/api/v1/me',
    'look-alike' => '/administrator',
    'backslash' => '/admin\\@evil.test',
]);

it('gives the code entry accessible digit boxes, an announced error region and one plain field', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    submitPassword($email, $password);
    $html = $this->get(route('admin.sign-in.code'))->getContent();

    expect(substr_count($html, 'data-box'))->toBe(6)
        ->and($html)->toContain('aria-label="Digit 1 of 6"')
        ->and($html)->toContain('aria-label="Digit 6 of 6"')
        ->and($html)->toContain('aria-live="assertive"')
        ->and(substr_count($html, 'name="one_time_code"'))->toBe(1);
});

it('counts the failed password and code in the audit trail without the email', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();

    submitPassword($email, 'wrong password here');
    submitPassword('nobody@example.test', 'wrong password here');

    $entries = DB::table('audit_entries')->where('event', 'sign_in_failed')->orderBy('occurred_at')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->subject_id)->toBe($operator->id)
        ->and($entries[1]->subject_id)->toBeNull()
        ->and(json_decode($entries[1]->detail, true))->toHaveKeys(['stage', 'lookup_prefix'])
        ->and(json_encode($entries))->not->toContain('nobody@example.test');
});
