<?php

/*
| Features plug sections into the shell without editing it (spec 006,
| FR-007, FR-008, FR-010, SC-006).
*/

use App\Modules\Admin\Http\Icons\UnknownIcon;
use App\Modules\Admin\Http\Navigation\DuplicateNavigationEntry;
use App\Modules\Admin\Http\Navigation\NavigationEntry;
use App\Modules\Admin\Http\Navigation\NavigationRegistry;
use App\Modules\Admin\Http\Navigation\UnknownNavigationGroup;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

function navigationEntry(string $key, string $group = 'operate', int $order = 0, string $route = 'admin.claims', ?Closure $badge = null, string $icon = 'claims'): NavigationEntry
{
    return new NavigationEntry(key: $key, group: $group, labelKey: 'admin::shell.groups.operate', icon: $icon, route: $route, order: $order, badge: $badge);
}

it('ships the operate and configure groups, omitting empty ones', function () {
    $registry = app(NavigationRegistry::class);

    expect($registry->groups())->toBe([]);

    $registry->add(navigationEntry('claims'));
    $registry->add(navigationEntry('plans', group: 'configure', route: 'admin.plans'));

    expect(array_map(fn (array $group): string => $group['group']->key, $registry->groups()))->toBe(['operate', 'configure']);
});

it('orders entries by order, then key', function () {
    $registry = app(NavigationRegistry::class);
    $registry->add(navigationEntry('users', order: 30, route: 'admin.users'));
    $registry->add(navigationEntry('claims', order: 20));
    $registry->add(navigationEntry('audit', order: 20, route: 'admin.audit'));

    expect(array_map(fn (array $item): string => $item['entry']->key, $registry->groups()[0]['entries']))->toBe(['audit', 'claims', 'users']);
});

it('refuses a second entry with the same key', function () {
    $registry = app(NavigationRegistry::class);
    $registry->add(navigationEntry('claims'));

    $registry->add(navigationEntry('claims'));
})->throws(DuplicateNavigationEntry::class);

it('refuses an entry in an undeclared group', function () {
    app(NavigationRegistry::class)->add(navigationEntry('claims', group: 'reports'));
})->throws(UnknownNavigationGroup::class);

it('refuses an entry with an unknown icon', function () {
    app(NavigationRegistry::class)->add(navigationEntry('claims', icon: 'rocket'));
})->throws(UnknownIcon::class);

it('marks the entry whose route matches, preferring the longest match', function (string $current, ?string $expected) {
    $registry = app(NavigationRegistry::class);
    $registry->add(navigationEntry('billing', route: 'admin.billing'));
    $registry->add(navigationEntry('claims', route: 'admin.billing.claims'));

    $currentKeys = array_values(array_map(
        fn (array $item): string => $item['entry']->key,
        array_filter($registry->groups($current)[0]['entries'], fn (array $item): bool => $item['current']),
    ));

    expect($currentKeys)->toBe($expected === null ? [] : [$expected]);
})->with([
    'exact' => ['admin.billing', 'billing'],
    'child of the longer route' => ['admin.billing.claims.show', 'claims'],
    'unrelated prefix' => ['admin.billingx', null],
]);

it('hides empty badges and survives a failing badge', function () {
    $registry = app(NavigationRegistry::class);
    $registry->add(navigationEntry('zero', route: 'admin.a', badge: fn () => 0));
    $registry->add(navigationEntry('none', route: 'admin.b', badge: fn () => null));
    $registry->add(navigationEntry('pending', route: 'admin.c', badge: fn () => 38));
    $registry->add(navigationEntry('broken', route: 'admin.d', badge: fn () => throw new RuntimeException('count failed')));

    $badges = collect($registry->groups()[0]['entries'])->mapWithKeys(fn (array $item): array => [$item['entry']->key => $item['badge']]);

    expect($badges->all())->toBe(['broken' => null, 'none' => null, 'pending' => 38, 'zero' => null]);
});

it('renders a registered section in the shell with its badge and current marker', function () {
    Route::get('/_test/claims', fn () => Blade::render('<x-admin::layouts.shell title="Claims">body</x-admin::layouts.shell>'))
        ->middleware('web')
        ->name('admin.test-claims');

    app(NavigationRegistry::class)->add(new NavigationEntry(
        key: 'test-claims',
        group: 'operate',
        labelKey: 'admin::shell.groups.operate',
        icon: 'claims',
        route: 'admin.test-claims',
        order: 20,
        badge: fn () => 38,
    ));

    $this->get('/_test/claims')
        ->assertSeeInOrder(['Operate', 'aria-current="page"', 'data-icon="claims"', '38'], escape: false);
});
