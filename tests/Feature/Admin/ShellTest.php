<?php

/*
| The back-office shell (spec 006, FR-006, FR-009, FR-010, FR-051).
*/

use App\Modules\Admin\Http\Shell\OperatorPresence;

it('renders the frame every back-office page shares', function () {
    $html = renderAdmin(<<<'BLADE'
        <x-admin::layouts.shell title="Claim CLM-2046" subtitle="Submitted 4 min ago" :breadcrumbs="[['label' => 'Billing', 'href' => '/admin/billing'], ['label' => 'CLM-2046']]">
            <x-slot:status><x-admin::pill tone="pending">Pending</x-admin::pill></x-slot:status>
            <x-slot:actions><x-admin::button>Approve…</x-admin::button></x-slot:actions>
            Page body
        </x-admin::layouts.shell>
        BLADE);

    expect($html)
        ->toContain('href="#content"')
        ->toMatch('/<nav aria-label="Primary"/')
        ->toContain('<main id="content"')
        ->toContain('x-on:admin-toast.window')
        ->toContain('<h1 class="text-h1 font-extrabold tracking-tight text-ink">Claim CLM-2046</h1>')
        ->toContain('Submitted 4 min ago')
        ->toContain('Pending')
        ->toContain('Approve…')
        ->toContain('aria-current="page" class="font-bold text-ink">CLM-2046')
        ->toContain('Page body');
});

it('marks production distinctly in the environment badge', function (string $environment, string $label, string $tone) {
    app()->detectEnvironment(fn () => $environment);

    expect(renderAdmin('<x-admin::layouts.shell title="Overview" />'))
        ->toContain("data-environment=\"{$environment}\"")
        ->toContain($label)
        ->toContain($tone);
})->with([
    ['production', 'Production', 'bg-over-soft'],
    ['staging', 'Staging', 'bg-sunken-2'],
    ['local', 'Local', 'bg-sunken-2'],
]);

it('shows the signed-in operator and sign-out when given', function () {
    $html = renderAdmin('<x-admin::layouts.shell title="Overview" :operator="$operator" sign-out-url="/admin/sign-out" />', [
        'operator' => new OperatorPresence('Mariam S.', 'M', 'admin::shell.operator.two_factor_on'),
    ]);

    expect($html)
        ->toContain('Mariam S.')
        ->toContain('Operator · 2FA on')
        ->toContain('action="/admin/sign-out"')
        ->toContain('aria-label="Sign out"');
});

it('shows only the language switch until sign-in exists', function () {
    $html = renderAdmin('<x-admin::layouts.shell title="Overview" />');

    expect($html)
        ->not->toContain('aria-label="Sign out"')
        ->toContain('العربية');
});

it('says so when nothing is registered', function () {
    expect(renderAdmin('<x-admin::layouts.shell title="Overview" />'))->toContain('Nothing to show yet.');
});

it('names the product from configuration', function () {
    config(['app.name' => 'Codename']);

    expect(renderAdmin('<x-admin::layouts.shell title="Overview" />'))->toContain('Codename')->toContain('Back office');
});
