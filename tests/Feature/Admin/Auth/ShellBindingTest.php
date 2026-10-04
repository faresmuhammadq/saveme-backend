<?php

/*
| The shell's operator block and sign-out are bound without editing the shell
| (spec 018, FR-090; spec 006 FR-051).
*/

use App\Modules\Admin\Http\Shell\OperatorPresence;

it('shows the signed-in operator, the second-factor status and a sign-out form', function (string $locale) {
    ['operator' => $operator] = makeOperator('enrolled', ['name' => 'Mariam Khalil']);
    signInOperator($operator);
    $this->withSession(['admin.locale' => $locale]);

    $html = $this->get('/admin')->assertOk()->getContent();
    app()->setLocale($locale);

    expect($html)
        ->toContain('Mariam Khalil')
        ->toContain('>M</div>')
        ->toContain(__('admin::shell.operator.two_factor_on'))
        ->toContain('action="'.route('admin.sign-out').'"');
})->with(['en', 'ar']);

it('uses the first grapheme of an Arabic name for the initial', function () {
    ['operator' => $operator] = makeOperator('enrolled', ['name' => 'مريم خليل']);
    signInOperator($operator);

    expect($this->get('/admin')->getContent())->toContain('>م</div>');
});

it('adds the operator sections to the sidebar only when an operator is signed in', function () {
    ['operator' => $operator] = makeOperator();

    $this->get('/admin/sign-in')->assertOk()->assertDontSee('/admin/security', false);

    signInOperator($operator);

    $this->get('/admin')->assertSee(route('admin.security'), false)->assertSee(route('admin.reenrolment-requests'), false);
});

it('leaves the shell file as spec 006 delivered it', function () {
    $shell = (string) file_get_contents(app_path('Modules/Admin/Http/Views/components/layouts/shell.blade.php'));

    // Spec 006's shell took the operator and the sign-out URL as props from its caller. Binding them here
    // must not have added any lookup of a guard, session or route to the file.
    expect($shell)->toContain("'operator' => null")
        ->and($shell)->toContain("'signOutUrl' => null")
        ->and($shell)->not->toContain('Auth::')
        ->and($shell)->not->toContain('session(')
        ->and($shell)->not->toContain('admin.sign-out');
});

it('keeps the gallery rendering its own sample operator', function () {
    registerAdminRoutesAs('local');
    ['operator' => $operator] = makeOperator('enrolled', ['name' => 'Real Operator']);
    signInOperator($operator);

    $html = $this->get('/admin/_gallery')->assertOk()->getContent();

    expect($html)->not->toContain('Real Operator')
        ->and(OperatorPresence::class)->toBeString();
});
