<?php

/*
| Sensitive acts ask for a second-factor proof from the last 10 minutes
| (spec 018, FR-060, FR-061; contracts/second-factor.md, Re-confirmation trait).
*/

use App\Modules\Admin\Http\Auth\OperatorSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\Feature\Admin\Fixtures\ReconfirmFixture;

beforeEach(function () {
    View::addNamespace('fixtures', dirname(__DIR__).'/Fixtures');
});

function staleFactor(): void
{
    session()->put(OperatorSession::FACTOR_CONFIRMED_AT, now()->subMinutes(11)->getTimestamp());
}

it('runs the act at once within 10 minutes of the last proof', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);

    Livewire::test(ReconfirmFixture::class)->call('act')->assertSet('runs', 1)->assertSet('factorOpen', false);
});

it('opens the prompt when the last proof is older, and runs the act after a correct new code', function () {
    ['operator' => $operator, 'secret' => $secret] = makeOperator();
    signInOperator($operator);
    staleFactor();

    $component = Livewire::test(ReconfirmFixture::class)->call('act')->assertSet('runs', 0)->assertSet('factorOpen', true);

    $this->travel(30)->seconds();
    $component->call('confirmFactor', currentCode($secret, 1))->assertSet('runs', 1)->assertSet('factorOpen', false);

    expect(app(OperatorSession::class)->hasRecentFactor())->toBeTrue();
});

it('refuses the code the sign-in just used', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();
    $code = currentCode($secret);
    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => $code]);
    staleFactor();

    Livewire::test(ReconfirmFixture::class)->call('act')->call('confirmFactor', $code)->assertSet('runs', 0)->assertHasErrors('one_time_code');
});

it('accepts and consumes a recovery code', function () {
    ['operator' => $operator] = makeOperator();
    $codes = giveRecoveryCodes($operator);
    signInOperator($operator);
    staleFactor();

    Livewire::test(ReconfirmFixture::class)->call('act')->call('confirmFactor', $codes[0])->assertSet('runs', 1);

    expect(DB::table('operator_recovery_codes')->where('operator_id', $operator->id)->whereNotNull('used_at')->count())->toBe(1);
});

it('records a wrong code as a failed re-confirmation and counts it toward the lock', function () {
    ['operator' => $operator, 'secret' => $secret] = makeOperator();
    signInOperator($operator);
    staleFactor();

    $component = Livewire::test(ReconfirmFixture::class)->call('act');

    foreach (range(1, 5) as $ignored) {
        $component->call('confirmFactor', '000000')->assertHasErrors('one_time_code');
    }

    $failures = DB::table('audit_entries')->where('event', 'sign_in_failed')->get();
    expect($failures)->toHaveCount(5)
        ->and(json_decode($failures[0]->detail, true))->toBe(['stage' => 'reconfirm']);

    $component->call('confirmFactor', currentCode($secret, 1))->assertSet('runs', 0);
    expect(DB::table('audit_entries')->where('event', 'lockout_applied')->count())->toBe(1);
});

it('cannot run the protected act from the browser or be redirected to another one', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    staleFactor();

    $component = Livewire::test(ReconfirmFixture::class)->call('act');

    expect(fn () => $component->call('doIt'))->toThrow(Exception::class)
        ->and(fn () => $component->set('factorMethod', 'render'))->toThrow(Exception::class, 'Cannot update locked property: [factorMethod]');
    $component->assertSet('runs', 0);
});
