<?php

/*
| Every back-office component update is judged again by the shared update
| endpoint (spec 018, FR-071, FR-073; constitution P-18 rules 4 and 7; R-8).
*/

use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\LocaleSwitcher;
use App\Modules\Admin\Http\Livewire\OperatorNotificationBell;
use App\Modules\Admin\Http\Middleware\BackOfficeHeaders;
use App\Modules\Admin\Http\Middleware\EnsureOperatorSignedIn;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Admin\Fixtures\GuardedFixture;

beforeEach(function () {
    View::addNamespace('fixtures', dirname(__DIR__).'/Fixtures');
    Route::middleware(['web', ResolveAdminLocale::class, BackOfficeHeaders::class, EnsureOperatorSignedIn::class])
        ->get('/admin/_fixture/guarded', GuardedFixture::class);
});

function fixtureSnapshot(): string
{
    return livewireSnapshot(test()->get('/admin/_fixture/guarded')->assertOk()->getContent(), GuardedFixture::class);
}

function bump(string $snapshot): TestResponse
{
    return livewireUpdate($snapshot, [['method' => 'bump', 'params' => [], 'metadata' => (object) []]]);
}

it('is unreachable as a page signed out', function () {
    $this->get('/admin/_fixture/guarded')->assertRedirect(route('admin.sign-in'));
});

it('runs an action for a signed-in operator', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);

    bump(fixtureSnapshot())->assertOk()->assertSee('count: 1', false);
});

it('refuses an update once the session has ended', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $snapshot = fixtureSnapshot();

    app(OperatorSession::class)->end();

    bump($snapshot)->assertStatus(401);
});

it('refuses an update after 30 minutes idle', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $snapshot = fixtureSnapshot();

    $this->travel(31)->minutes();

    bump($snapshot)->assertStatus(401);
});

it('refuses an update after the account changed under the session', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $snapshot = fixtureSnapshot();

    $operator->increment('credential_epoch');

    bump($snapshot)->assertStatus(401);
});

it('refuses an update from a session that is only half signed in', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $snapshot = fixtureSnapshot();

    app(OperatorSession::class)->end();
    signInOperatorPending($operator, SignInStage::SecondFactor);

    bump($snapshot)->assertStatus(401);
});

it('refuses the notification bell signed out', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $snapshot = livewireSnapshot($this->get('/admin')->assertOk()->getContent(), OperatorNotificationBell::class);

    app(OperatorSession::class)->end();

    livewireUpdate($snapshot)->assertStatus(401);
});

it('refuses a component rendered without a qualifying session even when mounted directly', function () {
    Livewire::test(GuardedFixture::class)->assertStatus(401);
});

it('keeps the language switch working on the sign-in page', function () {
    $snapshot = livewireSnapshot($this->get('/admin/sign-in')->assertOk()->getContent(), LocaleSwitcher::class);

    livewireUpdate($snapshot, [['method' => 'switchTo', 'params' => ['ar'], 'metadata' => (object) []]])->assertOk();

    expect(session('admin.locale'))->toBe('ar');
});
