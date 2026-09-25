<?php

/*
| The primitives gallery shows every primitive in every state (spec 006, US4,
| FR-040, SC-001), in both directions, without touching the database.
*/

use App\Modules\Admin\Http\Icons\IconRegistry;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    registerAdminRoutesAs('local');
});

it('renders inside the shell with every section', function () {
    $response = $this->get('/admin/_gallery');

    $response->assertSee('<main id="content"', escape: false)
        ->assertSeeInOrder(['Tokens', 'Actions', 'Forms', 'Data', 'Feedback', 'Navigation', 'Values', 'Reason prompt']);
});

it('shows every primitive in every state the catalogue lists', function () {
    $html = $this->get('/admin/_gallery')->getContent();

    $required = [
        ...collect(['primary', 'secondary', 'danger', 'ghost'])->crossJoin(['default', 'hover', 'focus', 'pressed', 'loading', 'disabled'])
            ->map(fn (array $pair): string => "button.{$pair[0]}.{$pair[1]}")->all(),
        'button.size.sm', 'button.size.md', 'button.size.lg', 'button.icon.leading', 'button.icon.trailing',
        'link.default.default', 'link.quiet.default', 'link.external.default',
        'icon-button.ghost.default', 'icon-button.outline.default', 'icon-button.filled.default', 'icon-button.danger.default',
        'icon-button.ghost.hover', 'icon-button.outline.focus', 'icon-button.ghost.disabled',
        'segmented.default.selected', 'split-button.primary.default', 'menu.default.open',
        'input.text.default', 'input.text.focus', 'input.text.invalid', 'input.text.disabled', 'search.default.default',
        'money-input.default.filled', 'number.default.default', 'textarea.default.counter',
        'select.default.closed', 'select.default.open',
        'checkbox.default.unchecked', 'checkbox.default.checked', 'checkbox.default.indeterminate', 'checkbox.default.disabled',
        'toggle.default.on', 'toggle.default.off', 'toggle.default.disabled', 'radio-group.card.selected',
        'date-picker.default.open', 'instant-picker.default.filled', 'validation-summary.default.errors', 'form-section.default.default',
        'stat-tile.hero.ring', 'stat-tile.delta.good', 'stat-tile.delta.bad', 'stat-tile.default.loading',
        'stat-tile.default.suppressed', 'stat-tile.default.sparkline', 'card.default.titled',
        'table.default.populated', 'table.row.selected', 'table.row.hover', 'table.default.empty', 'table.default.loading',
        'description-list.default.default',
        'pill.positive.active', 'pill.pending.in_grace', 'pill.over.revoked', 'pill.info.trial', 'pill.neutral.expired',
        'badge.accent.default', 'badge.count.default', 'badge.danger-count.default', 'badge.dot.default',
        'alert.info.default', 'alert.success.action', 'alert.warning.default', 'alert.error.dismissible',
        'toast.success.undo', 'toast.info.default', 'toast.error.sticky',
        'empty-state.positive.default', 'empty-state.search.action', 'skeleton.default.default',
        'dialog.default.open', 'confirm-dialog.danger.phrase', 'slide-over.default.open', 'reason-prompt.default.open', 'reason-prompt.live.default',
        'tabs.underline.current', 'tabs.pill.current', 'breadcrumbs.default.default', 'pagination.numbered.middle', 'pagination.cursor.middle',
        'money.default.default', 'money.compact.default', 'date.default.default', 'instant.default.default',
        'relative-time.default.default', 'identifier.default.default',
    ];

    $missing = array_values(array_filter($required, fn (string $item): bool => ! str_contains($html, "data-gallery-item=\"{$item}\"")));

    expect($missing)->toBe([]);
});

it('shows every icon', function () {
    $html = $this->get('/admin/_gallery')->getContent();

    foreach (app(IconRegistry::class)->names() as $name) {
        expect($html)->toContain("data-gallery-item=\"icon.{$name}.default\"");
    }
});

it('renders right-to-left in Arabic with Western digits only', function () {
    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get('/admin/_gallery')->getContent();

    expect($html)
        ->toContain('<html lang="ar" dir="rtl"')
        ->toContain('معرض المكوّنات')
        ->toUseWesternDigitsOnly();
});

it('reads nothing from the database', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/admin/_gallery')->assertOk();

    expect($queries)->toBe(0);
});
