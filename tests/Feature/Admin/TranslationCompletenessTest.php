<?php

/*
| Every back-office string exists in Arabic and English (spec 006, FR-036;
| constitution P-14).
*/

use Illuminate\Support\Arr;

function adminLangPath(string $locale = ''): string
{
    return app_path('Modules/Admin/Http/Lang'.($locale === '' ? '' : "/{$locale}"));
}

/**
 * @return array<string, array<string, mixed>> file => translations
 */
function adminTranslations(string $locale): array
{
    $files = [];

    foreach (glob(adminLangPath($locale).'/*.php') ?: [] as $path) {
        $files[basename($path, '.php')] = require $path;
    }

    ksort($files);

    return $files;
}

/**
 * @return list<string> every translation key literal used under the Admin module's Http layer
 */
function adminKeysInUse(): array
{
    $keys = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Admin/Http')));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Lang/')) {
            continue;
        }

        preg_match_all("/(?:__|trans|trans_choice|@lang)\(\s*'(admin::[\w.]+)'/", (string) file_get_contents($file->getPathname()), $matches);
        $keys = [...$keys, ...$matches[1]];
    }

    return array_values(array_unique($keys));
}

it('has the same translation files and keys in Arabic and English', function () {
    $english = adminTranslations('en');
    $arabic = adminTranslations('ar');

    expect(array_keys($arabic))->toBe(array_keys($english));

    foreach ($english as $file => $strings) {
        $missing = array_diff(array_keys(Arr::dot($strings)), array_keys(Arr::dot($arabic[$file])));
        $extra = array_diff(array_keys(Arr::dot($arabic[$file])), array_keys(Arr::dot($strings)));

        expect($missing)->toBe([], "ar/{$file}.php lacks: ".implode(', ', $missing))
            ->and($extra)->toBe([], "en/{$file}.php lacks: ".implode(', ', $extra));
    }
});

it('defines every key the back office uses, in both languages', function () {
    $undefined = [];

    foreach (adminKeysInUse() as $key) {
        $lookup = rtrim($key, '.');

        foreach (['en', 'ar'] as $locale) {
            if (! trans()->has($lookup, $locale, false)) {
                $undefined[] = "{$locale}: {$lookup}";
            }
        }
    }

    expect($undefined)->toBe([]);
});

it('writes Arabic strings with Western digits only', function () {
    expect(json_encode(adminTranslations('ar'), JSON_UNESCAPED_UNICODE))->toUseWesternDigitsOnly();
});

it('carries the sign-in, set-up and security strings in both languages', function () {
    $english = adminTranslations('en');
    $arabic = adminTranslations('ar');

    foreach (['auth', 'security'] as $file) {
        expect($english)->toHaveKey($file)->and($arabic)->toHaveKey($file);

        foreach (Arr::dot($english[$file]) as $key => $value) {
            expect($value)->toBeString()->not->toBe('')
                ->and(Arr::dot($arabic[$file])[$key] ?? '')->toBeString()->not->toBe('', "ar/{$file}.php: {$key}");
        }
    }
});

it('words the re-enrolment hint with two other operators in both languages', function () {
    expect(trans('admin::auth.second_factor.hint', ['remaining' => 4], 'en'))->toContain('two other operators')
        ->and(trans('admin::auth.second_factor.hint', ['remaining' => 4], 'ar'))->toContain('مشغّلَين آخرَين');
});
