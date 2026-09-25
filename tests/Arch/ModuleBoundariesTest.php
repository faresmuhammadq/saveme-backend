<?php

/*
|--------------------------------------------------------------------------
| P-02 Module boundaries
|--------------------------------------------------------------------------
|
| Code lives in app/Modules/<Context>/{Domain,Application,Infrastructure,Http}.
| Layers depend Http → Application → Domain and Infrastructure → Domain; a
| module never reaches into another module's Infrastructure or Http; the
| shared kernel depends on no module and holds no persistence.
|
| Every expectation passes on an empty tree and fails on the first violating
| class (verified in spec 005, research R-2).
|
*/

const CONTEXTS = [
    'Identity', 'Wallets', 'Budgeting', 'Sharing', 'Billing',
    'Sync', 'Reporting', 'Notifications', 'Admin',
];

foreach (CONTEXTS as $context) {
    $module = "App\\Modules\\{$context}";

    arch("P-02: {$context} Domain never touches persistence or the layers above it")
        ->expect("{$module}\\Domain")
        ->not->toUse([
            'Illuminate\\Database',
            'Illuminate\\Support\\Facades\\DB',
            "{$module}\\Application",
            "{$module}\\Infrastructure",
            "{$module}\\Http",
        ]);

    arch("P-02: {$context} Application does not depend on Http")
        ->expect("{$module}\\Application")
        ->not->toUse("{$module}\\Http");

    arch("P-02: {$context} Infrastructure depends only on Domain")
        ->expect("{$module}\\Infrastructure")
        ->not->toUse(["{$module}\\Application", "{$module}\\Http"]);

    $others = array_values(array_diff(CONTEXTS, [$context]));
    $forbidden = [];
    foreach ($others as $other) {
        $forbidden[] = "App\\Modules\\{$other}\\Infrastructure";
        $forbidden[] = "App\\Modules\\{$other}\\Http";
    }

    arch("P-02: {$context} never reaches into another module's Infrastructure or Http")
        ->expect($module)
        ->not->toUse($forbidden);
}

arch('P-02: the shared kernel depends on no module and holds no persistence')
    ->expect('App\Modules\Shared')
    ->not->toUse([
        ...array_map(fn (string $context): string => "App\\Modules\\{$context}", CONTEXTS),
        'Illuminate\Database',
        'Illuminate\Support\Facades\DB',
    ]);

test('P-02: app/Modules contains only the nine contexts and Shared', function () {
    $root = dirname(__DIR__, 2).'/app/Modules';

    if (! is_dir($root)) {
        expect(true)->toBeTrue();

        return;
    }

    $allowed = [...CONTEXTS, 'Shared'];
    $unknown = array_values(array_filter(
        array_diff(scandir($root) ?: [], ['.', '..']),
        fn (string $entry): bool => is_dir("{$root}/{$entry}") && ! in_array($entry, $allowed, true),
    ));

    expect($unknown)->toBe([], 'P-02: unknown module directory under app/Modules — the architecture gates do not cover it: '.implode(', ', $unknown));
});

test('P-02: every module keeps to the four layer directories', function () {
    $root = dirname(__DIR__, 2).'/app/Modules';

    if (! is_dir($root)) {
        expect(true)->toBeTrue();

        return;
    }

    $layers = ['Domain', 'Application', 'Infrastructure', 'Http'];
    $stray = [];
    foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $module) {
        if (! is_dir("{$root}/{$module}")) {
            continue;
        }
        $allowed = $module === 'Shared' ? ['Domain'] : $layers;
        foreach (array_diff(scandir("{$root}/{$module}") ?: [], ['.', '..']) as $entry) {
            if (! in_array($entry, $allowed, true)) {
                $stray[] = "{$module}/{$entry}";
            }
        }
    }

    expect($stray)->toBe([], 'P-02: entries outside the layer directories: '.implode(', ', $stray));
});
