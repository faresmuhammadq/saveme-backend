<?php

/*
|--------------------------------------------------------------------------
| P-03 Money
|--------------------------------------------------------------------------
|
| Money is an integer count of minor units (ADR-0003). No migration may
| declare a floating-point or decimal column, in the framework's migration
| directory or in any module's. Column types are Blueprint method calls, so
| this is a text scan rather than an import rule.
|
*/

function migrationFiles(): array
{
    $root = dirname(__DIR__, 2);

    return array_merge(
        glob("{$root}/database/migrations/*.php") ?: [],
        glob("{$root}/app/Modules/*/Infrastructure/Database/Migrations/*.php") ?: [],
    );
}

test('P-03: no migration declares a float, double, real or decimal column', function () {
    $patterns = [
        '/->\s*(decimal|unsignedDecimal|float|double|real)\s*\(/i' => 'Blueprint column',
        '/\b(numeric|decimal|real|double\s+precision|float[48]?)\s*(\(|\b)/i' => 'raw SQL type',
    ];
    $root = dirname(__DIR__, 2).'/';
    $violations = [];

    foreach (migrationFiles() as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            if (preg_match('~^\s*(//|\*|/\*|#)~', $line)) {
                continue;
            }
            foreach ($patterns as $pattern => $kind) {
                if ($kind === 'raw SQL type' && ! preg_match('/(statement|raw|unprepared)\s*\(/i', $line)) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $violations[] = str_replace($root, '', $file).':'.($number + 1).'  '.trim($line);
                }
            }
        }
    }

    expect($violations)->toBe([], "P-03: money is integer minor units (ADR-0003); these migrations declare a non-integer numeric column:\n  ".implode("\n  ", $violations));
});
