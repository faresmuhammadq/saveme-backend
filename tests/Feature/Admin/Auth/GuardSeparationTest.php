<?php

/*
| An application credential never reaches the back office (spec 018, FR-072;
| ADR-0006 rules 7 and 8; P-08 rule 4).
*/

use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\SecurityPage;
use App\Modules\Identity\Infrastructure\Database\Factories\DeviceSessionFactory;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use Illuminate\Support\Facades\Auth;

it('treats a valid application bearer token as signed out on every back-office page', function (string $path) {
    $bearer = ['Authorization' => 'Bearer '.DeviceSessionFactory::credentialFor(DeviceSession::factory()->create())];

    $this->withHeaders($bearer)->get($path)->assertRedirect(route('admin.sign-in'));
})->with(['/admin', '/admin/security', '/admin/security/authenticator', '/admin/reenrolment-requests']);

it('refuses a component update that carries only an application bearer token', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $snapshot = livewireSnapshot($this->get('/admin/security')->assertOk()->getContent(), SecurityPage::class);

    app(OperatorSession::class)->end();
    $bearer = ['Authorization' => 'Bearer '.DeviceSessionFactory::credentialFor(DeviceSession::factory()->create())];

    livewireUpdate($snapshot, headers: $bearer)->assertStatus(401);
});

it('never resolves an application user on the operator guard', function () {
    $session = DeviceSession::factory()->create();

    $this->withHeaders(['Authorization' => 'Bearer '.DeviceSessionFactory::credentialFor($session)])
        ->get('/admin/sign-in')->assertOk();

    expect(Auth::guard('operator')->check())->toBeFalse()
        ->and(Auth::guard('operator')->user())->toBeNull()
        ->and(config('auth.guards.operator.provider'))->toBe('operators')
        ->and(config('auth.providers.operators.model'))->not->toBe(config('auth.providers.users.model'));
});

it('lets a half-finished sign-in reach no guarded route', function (SignInStage $stage) {
    ['operator' => $operator] = makeOperator();
    signInOperatorPending($operator, $stage);

    foreach (['/admin', '/admin/security', '/admin/reenrolment-requests'] as $path) {
        $this->get($path)->assertRedirect();
        expect($this->get($path)->headers->get('Location'))->not->toBe(url($path));
    }

    expect(app(OperatorSession::class)->qualifies())->toBe('signed_out');
})->with(SignInStage::cases());
