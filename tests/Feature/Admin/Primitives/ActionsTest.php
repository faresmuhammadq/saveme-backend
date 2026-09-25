<?php

use Illuminate\View\ViewException;

/*
| Actions primitives (spec 006, FR-011; contracts/components.md → Actions).
*/

it('renders each button variant as a real button', function (string $variant) {
    $html = renderAdmin("<x-admin::button variant=\"{$variant}\">Approve</x-admin::button>");

    expect($html)
        ->toContain('<button')
        ->toContain('type="button"')
        ->toContain("data-variant=\"{$variant}\"")
        ->toContain('Approve');
})->with(['primary', 'secondary', 'danger', 'ghost']);

it('renders the three sizes', function (string $size, string $height) {
    expect(renderAdmin("<x-admin::button size=\"{$size}\">Save</x-admin::button>"))->toContain($height);
})->with([['sm', 'h-8'], ['md', 'h-10'], ['lg', 'h-12']]);

it('marks a loading button busy and shows a spinner instead of its icon', function () {
    $html = renderAdmin('<x-admin::button icon="check" :loading="true">Approve</x-admin::button>');

    expect($html)
        ->toContain('aria-busy="true"')
        ->toContain('animate-spin')
        ->not->toContain('data-icon="check"');
});

it('disables a disabled button', function () {
    expect(renderAdmin('<x-admin::button :disabled="true">Export</x-admin::button>'))->toMatch('/<button[^>]*\sdisabled/');
});

it('renders a link-styled button as an anchor, inert when disabled', function () {
    $enabled = renderAdmin('<x-admin::button href="/admin/claims">Open</x-admin::button>');
    $disabled = renderAdmin('<x-admin::button href="/admin/claims" :disabled="true">Open</x-admin::button>');

    expect($enabled)->toContain('<a')->toContain('href="/admin/claims"')
        ->and($disabled)->toContain('aria-disabled="true"')->toContain('tabindex="-1"')->not->toContain('href="/admin/claims"');
});

it('places a leading and a trailing icon', function () {
    $html = renderAdmin('<x-admin::button icon="check" icon-end="chevron-end">Go</x-admin::button>');

    expect($html)->toContain('data-icon="check"')->toContain('data-icon="chevron-end"');
    expect(strpos($html, 'data-icon="check"'))->toBeLessThan(strpos($html, 'Go'));
});

it('refuses an icon button without a label', function () {
    renderAdmin('<x-admin::icon-button icon="more" />');
})->throws(ViewException::class, 'needs a label');

it('names an icon button by its label', function () {
    expect(renderAdmin('<x-admin::icon-button icon="copy" label="Copy user ID" />'))
        ->toContain('aria-label="Copy user ID"');
});

it('opens external links in a new tab and says so', function () {
    expect(renderAdmin('<x-admin::link href="https://play.google.com/console" :external="true">Play Console</x-admin::link>'))
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('(opens in a new tab)');
});

it('renders a segmented control as a radio group with the value checked', function () {
    $html = renderAdmin('<x-admin::segmented name="range" value="week" :options="[[\'value\' => \'day\', \'label\' => \'Day\'], [\'value\' => \'week\', \'label\' => \'Week\']]" />');

    expect($html)->toContain('role="radiogroup"')->toMatch('/value="week"\s+checked/')
        ->and(substr_count($html, 'has-checked:bg-accent-soft'))->toBe(2);
});

it('renders a split button whose toggle announces a menu', function () {
    $html = renderAdmin('<x-admin::split-button menu-label="More grant options">Grant<x-slot:menu><x-admin::menu.item>Extend</x-admin::menu.item></x-slot:menu></x-admin::split-button>');

    expect($html)->toContain('aria-haspopup="menu"')->toContain('aria-label="More grant options"')->toContain('role="menuitem"');
});

it('renders a menu button with its items', function () {
    $html = renderAdmin(<<<'BLADE'
        <x-admin::menu label="Claim actions" :open="true">
            <x-admin::menu.heading>Claim CLM-2041</x-admin::menu.heading>
            <x-admin::menu.item icon="check" shortcut="A" :opens-prompt="true">Approve</x-admin::menu.item>
            <x-admin::menu.item :disabled="true" disabled-reason="after approval">Resend redeem code</x-admin::menu.item>
            <x-admin::menu.separator />
            <x-admin::menu.item tone="danger" :opens-prompt="true">Reject</x-admin::menu.item>
        </x-admin::menu>
        BLADE);

    expect($html)
        ->toContain('role="menu"')
        ->toContain('aria-haspopup="menu"')
        ->toContain('aria-expanded="true"')
        ->toContain('Approve…')
        ->toContain('aria-keyshortcuts="A"')
        ->toContain('role="separator"');
});

it('keeps a disabled menu item visible and describes why', function () {
    $html = renderAdmin('<x-admin::menu.item :disabled="true" disabled-reason="after approval">Resend redeem code</x-admin::menu.item>');

    preg_match('/aria-describedby="([^"]+)"/', $html, $describedBy);

    expect($html)->toContain('aria-disabled="true"')->toContain('Resend redeem code')
        ->and($describedBy)->toHaveCount(2)
        ->and($html)->toContain("id=\"{$describedBy[1]}\"");
});
