<?php

/*
| Signing in with a recovery code (spec 018, US3; FR-023, FR-024).
*/

use App\Modules\Admin\Application\SignIn\SecondFactorVerifier;
use App\Modules\Admin\Domain\Operators\FactorPurpose;
use App\Modules\Admin\Domain\Operators\Refusals\SecondFactorInvalid;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Auth\OperatorSession;
use Illuminate\Support\Facades\DB;

it('offers a single recovery field with a way back to the authenticator code', function () {
    ['operator' => $operator] = makeOperator();
    giveRecoveryCodes($operator);
    signInOperatorPending($operator, SignInStage::SecondFactor);

    $this->get(route('admin.sign-in.recovery'))
        ->assertOk()
        ->assertSee('Use a recovery code')
        ->assertSee('data-test="recovery-input"', false)
        ->assertSee('Use your authenticator code');
});

it('signs in with an unused code typed lower case without the hyphen, and consumes it', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();
    $codes = giveRecoveryCodes($operator);

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $typed = strtolower(str_replace('-', '', $codes[0]));
    $this->post(route('admin.sign-in.recovery.submit'), ['recovery_code' => $typed])->assertRedirect(route('admin.overview'));

    $this->get('/admin')->assertOk()->assertSee('Signed in with a recovery code. You have 9 of 10 left.');

    $entry = DB::table('audit_entries')->where('event', 'sign_in_succeeded')->sole();
    expect(json_decode($entry->detail, true))->toBe(['factor' => 'recovery_code', 'recovery_codes_left' => 9]);
});

it('refuses a used code like a wrong one and counts it as a failure', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();
    $codes = giveRecoveryCodes($operator);

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $this->post(route('admin.sign-in.recovery.submit'), ['recovery_code' => $codes[0]])->assertRedirect(route('admin.overview'));
    app(OperatorSession::class)->end();

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $this->post(route('admin.sign-in.recovery.submit'), ['recovery_code' => $codes[0]])->assertRedirect(route('admin.sign-in.recovery'));
    $this->get(route('admin.sign-in.recovery'))->assertSee("That recovery code didn't match or was already used.");

    expect(DB::table('audit_entries')->where('event', 'sign_in_failed')->count())->toBe(1);
});

it('lets a code be used once, however many times it is submitted', function () {
    ['operator' => $operator] = makeOperator();
    $codes = giveRecoveryCodes($operator);
    $verifier = app(SecondFactorVerifier::class);

    $first = $verifier->verify($operator->id, $codes[0], FactorPurpose::SignIn);

    expect($first->kind)->toBe('recovery_code')
        ->and($first->codesLeft)->toBe(9)
        ->and(fn () => $verifier->verify($operator->id, $codes[0], FactorPurpose::SignIn))->toThrow(SecondFactorInvalid::class);
});

it('warns on the overview at three codes or fewer', function (int $left, bool $warns) {
    ['operator' => $operator] = makeOperator();
    giveRecoveryCodes($operator, $left);
    signInOperator($operator);

    $page = $this->get('/admin')->assertOk();
    $warns ? $page->assertSee('data-test="low-codes"', false) : $page->assertDontSee('data-test="low-codes"', false);
})->with([[10, false], [4, false], [3, true], [1, true], [0, true]]);

it('tells an operator with no codes left to use "Lost your device?" instead', function () {
    ['operator' => $operator] = makeOperator();
    signInOperatorPending($operator, SignInStage::SecondFactor);

    $this->get(route('admin.sign-in.recovery'))
        ->assertOk()
        ->assertSee('You have no recovery codes left. Use “Lost your device?” instead.')
        ->assertDontSee('data-test="recovery-input"', false)
        ->assertSee(route('admin.sign-in.lost-device'), false);
});

it('counts a recovery-code sign-in as a recent second-factor proof', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();
    $codes = giveRecoveryCodes($operator);

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $this->post(route('admin.sign-in.recovery.submit'), ['recovery_code' => $codes[0]]);

    expect(app(OperatorSession::class)->hasRecentFactor())->toBeTrue();

    $this->travel(11)->minutes();
    expect(app(OperatorSession::class)->hasRecentFactor())->toBeFalse();
});
