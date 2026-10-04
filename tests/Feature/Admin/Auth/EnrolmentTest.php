<?php

/*
| First sign-in of a new operator: forced password change, enrolment with a
| setup key and link, recovery codes shown once (spec 018, US2; FR-004,
| FR-020 to FR-022, FR-026).
*/

use App\Modules\Admin\Domain\SecondFactor\Base32;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

const TEMPORARY = 'TEMP-PASSWORD-2026';

function newOperatorSignedInToPasswordStep(): array
{
    $made = makeOperator('awaiting_password_change', ['password' => TEMPORARY]);
    test()->post(route('admin.sign-in.submit'), ['email' => $made['email'], 'password' => TEMPORARY])
        ->assertRedirect(route('admin.password.new'));

    return $made;
}

function chooseNewPassword(string $password = 'a brand new passphrase'): TestResponse
{
    return test()->post(route('admin.password.new.submit'), ['password' => $password, 'password_confirmation' => $password]);
}

/** The raw secret behind the key the setup page shows. */
function secretFromSetupPage(string $html): string
{
    preg_match('/data-test="enrol-key"[^>]*>([^<]+)</', $html, $matches);

    return (string) Base32::decode(str_replace(' ', '', trim($matches[1])));
}

it('reaches only the password step until the password is changed', function () {
    newOperatorSignedInToPasswordStep();

    foreach (['/admin', '/admin/security', '/admin/enrol', '/admin/sign-in/code'] as $path) {
        $this->get($path)->assertRedirect(route('admin.password.new'));
    }
    $this->get(route('admin.password.new'))->assertOk()->assertSee('Choose a new password');
});

it('names the rule each refused password breaks', function (string $password, string $confirmation, string $message) {
    ['email' => $email] = newOperatorSignedInToPasswordStep();
    $password = $password === '{email}' ? $email : $password;

    $this->post(route('admin.password.new.submit'), ['password' => $password, 'password_confirmation' => $confirmation === '{same}' ? $password : $confirmation])
        ->assertRedirect(route('admin.password.new'));
    $this->get(route('admin.password.new'))->assertSee($message);
})->with([
    'too short' => ['short', '{same}', 'Use at least 12 characters.'],
    'the email' => ['{email}', '{same}', "Your password can't be your email."],
    'the temporary password' => [TEMPORARY, '{same}', 'Choose a password different from the temporary one.'],
    'not confirmed' => ['a brand new passphrase', 'something else entirely', "The passwords don't match."],
]);

it('moves to enrolment after a valid password and shows the setup key and link', function () {
    ['operator' => $operator, 'email' => $email] = newOperatorSignedInToPasswordStep();

    chooseNewPassword()->assertRedirect(route('admin.enrol'));
    $html = $this->get(route('admin.enrol'))->assertOk()->assertSee('Set up your authenticator')->getContent();

    preg_match('/data-test="enrol-key"[^>]*>([^<]+)</', $html, $key);
    $groups = explode(' ', trim($key[1]));
    $secret = secretFromSetupPage($html);

    expect($groups)->toHaveCount(8)
        ->and(array_map('strlen', $groups))->each->toBe(4)
        ->and(strlen($secret))->toBe(20)
        ->and($html)->toContain('otpauth://totp/Save%20Me%20Back%20office:'.rawurlencode($email))
        ->and($html)->toContain('secret='.str_replace(' ', '', trim($key[1])))
        ->and($html)->toContain('issuer=Save%20Me%20Back%20office&amp;algorithm=SHA1&amp;digits=6&amp;period=30')
        ->and($operator->refresh()->state)->toBe('awaiting_enrolment');
});

it('keeps the same key on reload, and a new one once it is older than 15 minutes', function () {
    ['operator' => $operator] = newOperatorSignedInToPasswordStep();
    chooseNewPassword();

    $first = secretFromSetupPage($this->get(route('admin.enrol'))->getContent());
    $again = secretFromSetupPage($this->get(route('admin.enrol'))->getContent());
    expect($again)->toBe($first);

    $operator->forceFill(['pending_secret_created_at' => now()->subMinutes(16)])->save();
    $later = secretFromSetupPage($this->get(route('admin.enrol'))->getContent());

    expect($later)->not->toBe($first);
});

it('keeps the key after a wrong code and completes with a correct one, showing ten recovery codes once', function () {
    ['operator' => $operator] = newOperatorSignedInToPasswordStep();
    chooseNewPassword();
    $secret = secretFromSetupPage($this->get(route('admin.enrol'))->getContent());

    $this->post(route('admin.enrol.submit'), ['one_time_code' => '000000'])->assertRedirect(route('admin.enrol'));
    $page = $this->get(route('admin.enrol'))->assertSee("That code didn't match. Check the key and your phone's time.");
    expect(secretFromSetupPage($page->getContent()))->toBe($secret);

    $response = $this->post(route('admin.enrol.submit'), ['one_time_code' => currentCode($secret)])
        ->assertOk()
        ->assertSee('Save your recovery codes');
    preg_match('/data-test="recovery-code-list".*?<\/ol>/s', $response->getContent(), $list);
    preg_match_all('/\b[0-9A-Z]{5}-[0-9A-Z]{5}\b/', $list[0], $codes);

    expect($codes[0])->toHaveCount(10)
        ->and(array_unique($codes[0]))->toHaveCount(10)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($operator->refresh()->state)->toBe('active');

    $stored = json_encode([
        session()->all(),
        DB::table('operator_recovery_codes')->get(),
        DB::table('operator_accounts')->get(),
        DB::table('audit_entries')->get(),
    ]);
    foreach ($codes[0] as $code) {
        expect($stored)->not->toContain($code)->not->toContain(str_replace('-', '', $code));
    }

    $this->get(route('admin.enrol'))->assertOk()->assertDontSee($codes[0][0])->assertSee('never shown a second time');
});

it('needs the saved-confirmation to finish, then signs in and lands on the overview', function () {
    newOperatorSignedInToPasswordStep();
    chooseNewPassword();
    $secret = secretFromSetupPage($this->get(route('admin.enrol'))->getContent());
    $this->post(route('admin.enrol.submit'), ['one_time_code' => currentCode($secret)])->assertOk();

    $this->post(route('admin.enrol.done'))->assertRedirect(route('admin.enrol'));
    $this->get('/admin')->assertRedirect();
    expect(app(OperatorSession::class)->qualifies())->toBe('signed_out');

    $this->post(route('admin.enrol.done'), ['saved' => '1'])->assertRedirect(route('admin.overview'));
    $this->get('/admin')->assertOk();

    expect(DB::table('audit_entries')->where('event', 'password_changed')->count())->toBe(1)
        ->and(DB::table('audit_entries')->where('event', 'sign_in_succeeded')->count())->toBe(1)
        ->and(collect(DB::table('audit_entries')->where('event', 'admin_action')->get())->map(fn ($e) => json_decode($e->detail, true)['action'])->all())->toBe(['enrolment_completed']);
});

it('cannot replay the enrolment code to sign in', function () {
    ['operator' => $operator, 'email' => $email] = newOperatorSignedInToPasswordStep();
    chooseNewPassword('a brand new passphrase');
    $secret = secretFromSetupPage($this->get(route('admin.enrol'))->getContent());
    $code = currentCode($secret);
    $this->post(route('admin.enrol.submit'), ['one_time_code' => $code]);
    $this->post(route('admin.enrol.done'), ['saved' => '1']);
    app(OperatorSession::class)->end();

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => 'a brand new passphrase'])->assertRedirect(route('admin.sign-in.code'));
    $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => $code])->assertRedirect(route('admin.sign-in.code'));
    expect($operator->refresh()->last_code_step)->not->toBeNull();
});

it('asks an enrolled operator with a reset password for the code before the new password', function () {
    ['operator' => $operator, 'email' => $email, 'secret' => $secret] = makeOperator();
    Artisan::call('admin:operator:reset-password', ['email' => $email]);
    preg_match('/\(shown once\): (\S+)/', Artisan::output(), $temporary);

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $temporary[1]])->assertRedirect(route('admin.sign-in.code'));
    $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => currentCode($secret)])->assertRedirect(route('admin.password.new'));
    $this->get('/admin')->assertRedirect(route('admin.password.new'));

    chooseNewPassword()->assertRedirect(route('admin.overview'));
    $this->get('/admin')->assertOk();

    expect($operator->refresh()->state)->toBe('active')
        ->and(DB::table('audit_entries')->where('event', 'sign_in_succeeded')->count())->toBe(1);
});

it('refuses an expired temporary password like a wrong one', function () {
    $made = makeOperator('awaiting_password_change', ['password' => TEMPORARY]);
    $this->travel(73)->hours();

    $this->post(route('admin.sign-in.submit'), ['email' => $made['email'], 'password' => TEMPORARY])->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('Email or password is incorrect.');
});

it('discards the set-up steps after 15 minutes', function () {
    newOperatorSignedInToPasswordStep();
    $this->travel(16)->minutes();

    $this->get(route('admin.password.new'))->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('Your sign-in timed out. Start again.');
});

it('does not let an operator in the enrolment stage skip to the codes', function () {
    newOperatorSignedInToPasswordStep();
    chooseNewPassword();

    $this->post(route('admin.enrol.done'), ['saved' => '1'])->assertRedirect(route('admin.enrol'));
    expect(OperatorAccount::query()->where('state', 'active')->count())->toBe(0);
});
