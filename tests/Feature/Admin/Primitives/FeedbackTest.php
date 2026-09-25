<?php

/*
| Feedback and overlay primitives (spec 006, FR-014, FR-017; contracts/components.md → Feedback).
*/

it('renders a status pill in each tone', function (string $tone, string $class) {
    expect(renderAdmin("<x-admin::pill tone=\"{$tone}\">Status</x-admin::pill>"))->toContain($class);
})->with([
    ['positive', 'bg-positive-soft'],
    ['pending', 'bg-pending-soft'],
    ['over', 'bg-over-soft'],
    ['info', 'bg-accent-soft'],
    ['neutral', 'bg-sunken-2'],
]);

it('gives a dot badge an accessible name', function () {
    expect(renderAdmin('<x-admin::badge tone="dot" label="3 new notifications" />'))
        ->toContain('<span class="sr-only">3 new notifications</span>');
});

it('announces info and success politely and warnings and errors assertively', function (string $tone, string $role) {
    expect(renderAdmin("<x-admin::alert tone=\"{$tone}\" title=\"Heads up.\">Body</x-admin::alert>"))->toContain("role=\"{$role}\"");
})->with([['info', 'status'], ['success', 'status'], ['warning', 'alert'], ['error', 'alert']]);

it('announces toasts through a live region', function () {
    expect(renderAdmin('<x-admin::toast-stack />'))
        ->toContain('aria-live="polite"')
        ->toContain('x-on:admin-toast.window');
});

it('uses the requested heading level in an empty state', function () {
    expect(renderAdmin('<x-admin::empty-state title="No user matches" :level="3">Search needs a full email.</x-admin::empty-state>'))
        ->toContain('<h3')->toContain('No user matches');
});

it('hides skeletons from assistive technology', function () {
    expect(renderAdmin('<x-admin::skeleton :lines="2" />'))->toContain('aria-hidden="true"');
});

it('renders a dialog as a labelled native dialog', function () {
    $html = renderAdmin('<x-admin::dialog name="revoke" title="Revoke this subscription?" description="Plus features end now.">Body</x-admin::dialog>');

    expect($html)
        ->toContain('<dialog')
        ->toContain('id="revoke"')
        ->toContain('aria-labelledby="revoke-title"')
        ->toContain('aria-describedby="revoke-description"')
        ->toContain('x-trap.noscroll')
        ->toContain('aria-label="Close"');
});

it('opens a confirmation as an alert dialog with Cancel focused and Confirm locked until the phrase is typed', function () {
    $html = renderAdmin('<x-admin::confirm-dialog name="revoke" title="Revoke this subscription?" confirm-phrase="REVOKE" confirm="revoke">Plus features end immediately.</x-admin::confirm-dialog>');

    expect($html)
        ->toContain('role="alertdialog"')
        ->toMatch('/<button[^>]*autofocus[^>]*>\s*<span>Cancel/s')
        ->toMatch('/<button[^>]*disabled[^>]*data-confirm/s')
        ->toContain('wire:click="revoke"')
        ->toContain('REVOKE');
});

it('slides an overlay in from the end edge', function () {
    expect(renderAdmin('<x-admin::slide-over name="user-preview" title="omar.farouk@example.com">Body</x-admin::slide-over>'))
        ->toContain('<dialog')
        ->toContain('ms-auto me-0');
});

it('renders the reason prompt with its action copy, counter, attribution and a locked confirm', function () {
    $html = renderAdmin('<x-admin::reason-prompt action-key="fixture.approve" :presets="[\'reference_verified\', \'amount_matches\']" attribution="Mariam S." />');

    expect($html)
        ->toContain('Approve the sample')
        ->toContain('Records a sample approval.')
        ->toContain('Why are you taking this action?')
        ->toContain('Reference verified')
        ->toContain('maxlength="500"')
        ->toContain('<span x-text="[...reason].length">0</span> / 500')
        ->toContain('Written to the audit log as Mariam S.')
        ->toContain('<bdi dir="ltr" class="font-mono">admin_action.fixture.approve</bdi>')
        ->toMatch('/<button[^>]*disabled[^>]*data-confirm/s');
});

it('shows a reason error on the textarea', function () {
    $html = renderAdmin('<x-admin::reason-prompt action-key="fixture.approve" error="Give a reason." />');

    expect($html)->toContain('aria-invalid="true"')->toContain('id="reason-prompt-error"')->toContain('Give a reason.');
});
