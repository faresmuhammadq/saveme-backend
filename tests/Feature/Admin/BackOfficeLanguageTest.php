<?php

use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * Every B12 screen in Arabic: right-to-left, Western digits, identifiers
 * isolated left-to-right, toasts announced politely (spec 021, FR-007,
 * FR-056). The physical-direction and arbitrary-colour gates over the new
 * views are tests/Arch/AdminBackOfficeTest.php (FR-031, FR-001).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
    $this->user = User::factory()->withEmail('ltr.person@example.test')->verified()->create();
    Subscription::factory()->provider('manual')->create(['user_id' => $this->user->id]);
    $this->claim = PaymentClaim::factory()->forAddress('payer.ltr@example.test')->reference('ARREF0000001')->create();
    $this->plan = Plan::query()->where('code', 'plus_monthly')->sole();
});

function backOfficeScreens(): array
{
    return [
        'overview' => fn () => route('admin.overview'),
        'claim queue' => fn () => route('admin.claims'),
        'claim review' => fn () => route('admin.claims.show', ['claim' => test()->claim->id]),
        'find account' => fn () => route('admin.accounts'),
        'account page' => fn () => route('admin.accounts.show', ['user' => test()->user->id]),
        'plan catalogue' => fn () => route('admin.plans'),
        'plan editor' => fn () => route('admin.plans.edit', ['plan' => test()->plan->id]),
        'audit log' => fn () => route('admin.audit'),
    ];
}

it('renders every screen right-to-left in Arabic with Western digits', function (string $screen) {
    $url = backOfficeScreens()[$screen]();

    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get($url)->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false)
        ->getContent();

    expect(preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', strip_tags($html)))->toBe(0, 'an Arabic-Indic digit was rendered');
})->with(array_keys(backOfficeScreens()));

it('renders every screen left-to-right in English', function (string $screen) {
    $this->withSession([ResolveAdminLocale::SESSION_KEY => 'en'])->get(backOfficeScreens()[$screen]())->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false);
})->with(array_keys(backOfficeScreens()));

it('keeps references and addresses inside left-to-right isolates in Arabic', function () {
    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])
        ->get(route('admin.claims.show', ['claim' => $this->claim->id]))->assertOk()->getContent();

    expect($html)->toContain('<bdi dir="ltr"')
        ->and(preg_match('/<bdi dir="ltr"[^>]*>\s*ARREF0000001\s*<\/bdi>/', $html))->toBe(1)
        ->and(preg_match('/<bdi dir="ltr"[^>]*>\s*payer\.ltr@example\.test\s*<\/bdi>/', $html))->toBe(1);
});

it('has a polite live region for toasts on every screen', function (string $screen) {
    $this->get(backOfficeScreens()[$screen]())->assertOk()->assertSee('aria-live="polite"', false);
})->with(array_keys(backOfficeScreens()));

it('builds every B12 dialog on the shared dialog primitive that traps and returns focus', function () {
    $dialog = file_get_contents(app_path('Modules/Admin/Http/Views/components/dialog.blade.php'));
    $viewsWithDialogs = ['account-page', 'claim-review', 'plan-editor'];

    expect($dialog)->toContain('x-trap.noscroll');

    foreach ($viewsWithDialogs as $view) {
        $source = file_get_contents(app_path("Modules/Admin/Http/Views/livewire/{$view}.blade.php"));

        expect($source)->toMatch('/<x-admin::(confirm-dialog|dialog|reason-prompt|factor-prompt)/')
            ->and($source)->not->toContain('<dialog');
    }
});
