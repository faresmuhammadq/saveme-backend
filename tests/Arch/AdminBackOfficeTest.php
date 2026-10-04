<?php

/*
|--------------------------------------------------------------------------
| P-18 Delivery surfaces — the back office (spec 006)
|--------------------------------------------------------------------------
|
| Livewire classes live only in the Admin module and never hold an Eloquent
| model in public state. Back-office views are the Admin module's, not the
| shared resources/views tree. Admin views use logical (start/end) utilities
| only, so right-to-left works by construction, and take every colour and
| size from the token set.
|
*/

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * @return list<string> class names declared under app/
 */
function appClasses(): array
{
    $root = dirname(__DIR__, 2);
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app")) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) === 1
            && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class) === 1) {
            $classes[] = "{$namespace[1]}\\{$class[1]}";
        }
    }

    return $classes;
}

/**
 * @return array<string, list<array{line: int, text: string}>> Admin Blade view path => matching lines
 */
function adminViewLinesMatching(string $pattern): array
{
    $root = dirname(__DIR__, 2).'/app/Modules/Admin/Http/Views';
    $hits = [];

    if (! is_dir($root)) {
        return [];
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $hits[str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname())][] = ['line' => $index + 1, 'text' => trim($line)];
            }
        }
    }

    return $hits;
}

/**
 * @param  array<string, list<array{line: int, text: string}>>  $hits
 */
function describeHits(array $hits): string
{
    $lines = [];

    foreach ($hits as $path => $matches) {
        foreach ($matches as $match) {
            $lines[] = "{$path}:{$match['line']}  {$match['text']}";
        }
    }

    return implode("\n", $lines);
}

test('P-18 rule 3: Livewire components live only in the Admin module', function () {
    $misplaced = array_values(array_filter(
        appClasses(),
        fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, Component::class)
            && ! str_starts_with($class, 'App\\Modules\\Admin\\Http\\Livewire\\'),
    ));

    expect($misplaced)->toBe([], 'P-18 rule 3: Livewire components outside app/Modules/Admin/Http/Livewire: '.implode(', ', $misplaced));
});

test('P-18 rule 3: back-office views are not placed in the shared resources/views tree', function () {
    $root = dirname(__DIR__, 2).'/resources/views';
    $stray = array_values(array_filter(
        ['admin', 'livewire', 'components/admin', 'pages'],
        fn (string $directory): bool => is_dir("{$root}/{$directory}"),
    ));

    expect($stray)->toBe([], 'P-18 rule 3: register back-office views from the Admin module, not resources/views/'.implode(', ', $stray));
});

test('P-18 rule 5: no Livewire public property holds an Eloquent model', function () {
    $offending = [];

    foreach (appClasses() as $class) {
        if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            $types = match (true) {
                $type instanceof ReflectionNamedType => [$type],
                $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => $type->getTypes(),
                default => [],
            };

            foreach ($types as $named) {
                if ($named instanceof ReflectionNamedType && ! $named->isBuiltin() && is_a($named->getName(), Model::class, true)) {
                    $offending[] = "{$class}::\${$property->getName()}";
                }
            }
        }
    }

    expect($offending)->toBe([], 'P-18 rule 5: Livewire public state must be scalars or DTOs, never models: '.implode(', ', $offending));
});

test('FR-031: Admin views use logical start/end utilities, never physical left/right ones', function () {
    $physical = '/(?<![\w-])(?:[a-z]+:)*(?:-?m[lr]|p[lr]|left|right|border-[lr]|rounded-[lr]|rounded-(?:tl|tr|bl|br)|text-(?:left|right))(?:-[\w.\/\[\]]+)?(?![\w-])/';

    $hits = adminViewLinesMatching('/class(?:es)?=|->class\(|class:\s/');
    $violations = [];

    foreach ($hits as $path => $lines) {
        foreach ($lines as $line) {
            preg_match_all('/(?:class|classes)="([^"]*)"|\'([^\']*)\'/', $line['text'], $chunks);

            foreach (array_merge($chunks[1], $chunks[2]) as $chunk) {
                if (preg_match($physical, $chunk) === 1) {
                    $violations[$path][] = $line;
                    break;
                }
            }
        }
    }

    expect($violations)->toBe([], "FR-031: physical-direction utilities break right-to-left; use ms/me/ps/pe/start/end/text-start/text-end/border-s/border-e/rounded-s/rounded-e:\n".describeHits($violations));
});

test('FR-001: Admin views take colours and sizes from the token set, never arbitrary values', function () {
    $hits = adminViewLinesMatching('/\[#[0-9a-fA-F]{3,8}\]|-\[\d+(?:\.\d+)?(?:px|rem)\]|style="[^"]*(?:color|background|margin|padding)\s*:/');

    expect($hits)->toBe([], "FR-001: use a token utility instead of an arbitrary value:\n".describeHits($hits));
});

test('P-18 rules 4 and 7: every back-office component extends BackOfficeComponent, except the language switch and the local gallery', function () {
    $base = 'App\\Modules\\Admin\\Http\\Livewire\\BackOfficeComponent';
    $exempt = ['App\\Modules\\Admin\\Http\\Livewire\\LocaleSwitcher', 'App\\Modules\\Admin\\Http\\Livewire\\Gallery', $base];

    $offending = array_values(array_filter(
        appClasses(),
        fn (string $class): bool => str_starts_with($class, 'App\\Modules\\Admin\\Http\\Livewire\\')
            && ! str_contains($class, '\\Concerns\\')
            && ! in_array($class, $exempt, true)
            && class_exists($class)
            && is_subclass_of($class, Component::class)
            && ! is_subclass_of($class, $base),
    ));

    expect($offending)->toBe([], 'P-18 rule 7: back-office components must extend BackOfficeComponent, which re-checks the session on every update: '.implode(', ', $offending));
});

test('FR-027: the admin commands are exactly the contracted four, and nothing switches the second factor off', function () {
    $root = dirname(__DIR__, 2).'/app/Modules/Admin/Http';
    $signatures = [];

    foreach (glob("{$root}/Console/*.php") ?: [] as $file) {
        if (preg_match('/\$signature\s*=\s*\'([^\s\']+)/', (string) file_get_contents($file), $matches) === 1) {
            $signatures[] = $matches[1];
        }
    }
    sort($signatures);

    expect($signatures)->toBe([
        'admin:expire-reenrolment-requests',
        'admin:operator:create',
        'admin:operator:deactivate',
        'admin:operator:reset-password',
    ]);

    $names = [];
    preg_match_all('/->name\(\'([^\']+)\'\)/', (string) file_get_contents("{$root}/routes.php"), $routeNames);
    $names = [...$signatures, ...$routeNames[1]];

    $switches = array_values(array_filter($names, fn (string $name): bool => preg_match('/disable|two-factor-off|bypass|skip/i', $name) === 1));

    expect($switches)->toBe([], 'FR-027: no route or command may turn the second factor off: '.implode(', ', $switches));
});
