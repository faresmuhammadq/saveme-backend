<?php

/*
| An operator's own sign-in security (spec 018, US5; FR-024, FR-025,
| FR-060 to FR-062).
*/

use App\Modules\Admin\Application\SignIn\SecondFactorVerifier;
use App\Modules\Admin\Domain\Operators\FactorPurpose;
use App\Modules\Admin\Domain\Operators\Refusals\SecondFactorInvalid;
use App\Modules\Admin\Domain\Ports\SecretSealer;
use App\Modules\Admin\Domain\SecondFactor\Totp;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\ReplaceAuthenticator;
use App\Modules\Admin\Http\Livewire\SecurityPage;
use App\Modules\Admin\Http\Presenters\TemporalPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function stalenessOfFactor(): void
{
    session()->put(OperatorSession::FACTOR_CONFIRMED_AT, now()->subMinutes(11)->getTimestamp());
}

it('shows the linked date, the codes left and the last sign-in in the operator timezone', function () {
    ['operator' => $operator] = makeOperator();
    giveRecoveryCodes($operator, 7);
    $operator->forceFill(['last_signed_in_at' => '2026-10-03 10:00:00+00', 'enrolled_at' => '2026-09-01 08:30:00+00'])->save();
    signInOperator($operator);
    $operator->forceFill(['last_signed_in_at' => '2026-10-03 10:00:00+00'])->save();

    $instant = fn (string $iso): string => app(TemporalPresenter::class)->instant($iso, config('admin.timezone'), 'en');

    $this->get('/admin/security')
        ->assertOk()
        ->assertSee('7 of 10 left')
        ->assertSee($instant('2026-10-03T10:00:00.000000+00:00'))
        ->assertSee($instant('2026-09-01T08:30:00.000000+00:00'))
        ->assertSee('data-test="security-change-password"', false)
        ->assertSee('data-test="security-regenerate"', false)
        ->assertSee('data-test="security-replace"', false);
});

it('changes the password with the current one, keeps this session and ends the others', function () {
    ['operator' => $operator, 'password' => $password] = makeOperator();
    signInOperator($operator);
    $epoch = $operator->refresh()->credential_epoch;

    Livewire::test(SecurityPage::class)
        ->set('currentPassword', $password)
        ->set('newPassword', 'a different long passphrase')
        ->set('newPasswordConfirmation', 'a different long passphrase')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertDispatched('admin-toast', tone: 'success', message: 'Password changed. Other sessions were signed out.')
        ->assertSet('currentPassword', '')
        ->assertSet('newPassword', '')
        ->assertSet('newPasswordConfirmation', '');

    $operator->refresh();
    expect(Hash::check('a different long passphrase', $operator->password_hash))->toBeTrue()
        ->and($operator->credential_epoch)->toBe($epoch + 1)
        ->and(app(OperatorSession::class)->qualifies())->toBeNull()
        ->and(DB::table('audit_entries')->where('event', 'password_changed')->count())->toBe(1);

    // Another browser still holds the old epoch.
    session()->put(OperatorSession::EPOCH, $epoch);
    expect(app(OperatorSession::class)->qualifies())->toBe('epoch');
});

it('never sends a password back in the component snapshot, after success or failure', function () {
    ['operator' => $operator, 'password' => $password] = makeOperator();
    signInOperator($operator);
    $snapshot = livewireSnapshot($this->get('/admin/security')->getContent(), SecurityPage::class);

    $wrong = livewireUpdate($snapshot, [['method' => 'changePassword', 'params' => [], 'metadata' => (object) []]], updates: [
        'currentPassword' => 'not my password at all', 'newPassword' => 'second secret passphrase', 'newPasswordConfirmation' => 'second secret passphrase',
    ]);
    $wrong->assertOk()->assertDontSee('second secret passphrase')->assertDontSee('not my password at all');

    $right = livewireUpdate($snapshot, [['method' => 'changePassword', 'params' => [], 'metadata' => (object) []]], updates: [
        'currentPassword' => $password, 'newPassword' => 'third secret passphrase', 'newPasswordConfirmation' => 'third secret passphrase',
    ]);
    $right->assertOk()->assertDontSee('third secret passphrase')->assertDontSee($password);

    expect(Hash::check('third secret passphrase', $operator->refresh()->password_hash))->toBeTrue();
});

it('refuses a wrong current password, a mismatch and a weak new password', function (string $current, string $new, string $confirmation, string $field) {
    ['operator' => $operator, 'password' => $password] = makeOperator();
    signInOperator($operator);

    Livewire::test(SecurityPage::class)
        ->set('currentPassword', $current === '{current}' ? $password : $current)
        ->set('newPassword', $new)
        ->set('newPasswordConfirmation', $confirmation)
        ->call('changePassword')
        ->assertHasErrors($field)
        ->assertSet('newPassword', '');

    expect(Hash::check($password, $operator->refresh()->password_hash))->toBeTrue();
})->with([
    'wrong current' => ['nope nope nope', 'a different long passphrase', 'a different long passphrase', 'currentPassword'],
    'mismatch' => ['{current}', 'a different long passphrase', 'another passphrase here', 'newPassword'],
    'too short' => ['{current}', 'short', 'short', 'newPassword'],
    'same as current' => ['{current}', 'a long operator password', 'a long operator password', 'newPassword'],
]);

it('asks for the code before the password form when the last proof is stale', function () {
    ['operator' => $operator, 'secret' => $secret] = makeOperator();
    signInOperator($operator);
    stalenessOfFactor();

    Livewire::test(SecurityPage::class)
        ->call('startPasswordChange')
        ->assertSet('factorOpen', true)
        ->assertNotDispatched('admin-dialog-open')
        ->call('confirmFactor', currentCode($secret, 1))
        ->assertSet('factorOpen', false)
        ->assertDispatched('admin-dialog-open', name: 'change-password');
});

it('shows ten new recovery codes once, in the response that made them, and ends the old set', function () {
    ['operator' => $operator] = makeOperator();
    $old = giveRecoveryCodes($operator);
    signInOperator($operator);
    $snapshot = livewireSnapshot($this->get('/admin/security')->getContent(), SecurityPage::class);

    $response = livewireUpdate($snapshot, [['method' => 'regenerate', 'params' => [], 'metadata' => (object) []]])->assertOk();
    $body = json_decode((string) $response->getContent(), true)['components'][0];

    preg_match('/data-test="recovery-code-list".*?<\/ol>/s', $body['effects']['html'], $list);
    preg_match_all('/\b[0-9A-Z]{5}-[0-9A-Z]{5}\b/', $list[0], $codes);

    expect($codes[0])->toHaveCount(10)
        ->and($body['snapshot'])->not->toContain($codes[0][0])
        ->and(array_intersect($codes[0], $old))->toBe([])
        ->and(DB::table('audit_entries')->where('event', 'admin_action')->get()->map(fn ($e) => json_decode($e->detail, true)['action'])->all())->toBe(['recovery_codes_regenerated']);

    foreach (array_slice($old, 0, 3) as $code) {
        expect(fn () => app(SecondFactorVerifier::class)->verify($operator->id, $code, FactorPurpose::Reconfirm))->toThrow(SecondFactorInvalid::class);
    }
    expect(app(SecondFactorVerifier::class)->verify($operator->id, $codes[0][0], FactorPurpose::Reconfirm)->kind)->toBe('recovery_code');
});

it('does not show the new codes on the next render', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);

    Livewire::test(SecurityPage::class)
        ->call('regenerate')
        ->assertSeeHtml('data-test="recovery-code-list"')
        ->call('$refresh')
        ->assertDontSeeHtml('data-test="recovery-code-list"');
});

it('keeps the old authenticator until the new one is confirmed', function () {
    ['operator' => $operator, 'secret' => $old] = makeOperator();
    signInOperator($operator);

    $component = Livewire::test(ReplaceAuthenticator::class)->assertSet('ready', true)->assertSee('Your current authenticator keeps working');
    $new = app(SecretSealer::class)->open($operator->refresh()->pending_secret_sealed, $operator->id, 'pending_secret');

    // Abandoned: the old one still works.
    expect($operator->secret_sealed)->not->toBeNull()
        ->and(app(SecondFactorVerifier::class)->verify($operator->id, currentCode($old), FactorPurpose::Reconfirm)->kind)->toBe('code');

    $this->travel(30)->seconds();
    $component->set('oneTimeCode', Totp::codeAt($new, Totp::stepAt(now()->getTimestamp())))
        ->call('confirm')
        ->assertRedirect(route('admin.security'));

    $this->travel(30)->seconds();
    expect(fn () => app(SecondFactorVerifier::class)->verify($operator->id, currentCode($old), FactorPurpose::Reconfirm))->toThrow(SecondFactorInvalid::class)
        ->and(app(SecondFactorVerifier::class)->verify($operator->id, Totp::codeAt($new, Totp::stepAt(now()->getTimestamp())), FactorPurpose::Reconfirm)->kind)->toBe('code')
        ->and(DB::table('audit_entries')->where('event', 'admin_action')->get()->map(fn ($e) => json_decode($e->detail, true)['action'])->all())->toBe(['authenticator_replaced']);
});

it('keeps the setup key out of the page until a fresh proof is given', function () {
    ['operator' => $operator, 'secret' => $secret] = makeOperator();
    signInOperator($operator);
    stalenessOfFactor();

    $component = Livewire::test(ReplaceAuthenticator::class)->assertSet('ready', false)->assertSet('factorOpen', true)->assertDontSee('data-test="enrol-key"');
    expect($operator->refresh()->pending_secret_sealed)->toBeNull();

    $this->travel(30)->seconds();
    $component->call('confirmFactor', currentCode($secret, 1))->assertSet('ready', true)->assertSeeHtml('data-test="enrol-key"');
    expect($operator->refresh()->pending_secret_sealed)->not->toBeNull();
});

it('asks for no further code after a recovery-code sign-in within 10 minutes', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();
    $codes = giveRecoveryCodes($operator);
    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $this->post(route('admin.sign-in.recovery.submit'), ['recovery_code' => $codes[0]]);

    Livewire::test(ReplaceAuthenticator::class)->assertSet('ready', true)->assertSet('factorOpen', false);
});
