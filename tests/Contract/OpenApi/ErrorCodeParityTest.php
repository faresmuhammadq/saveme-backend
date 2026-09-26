<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use Symfony\Component\Yaml\Yaml;

/*
 * The ErrorCode enum is the runtime's copy of the one error catalogue
 * (spec 003). It must name exactly the catalogued codes, and serve each with
 * the catalogue's status, title and retry class (spec 007, R-15).
 */

function errorCatalogue(): array
{
    return Yaml::parseFile(dirname(__DIR__, 3).'/docs/openapi/error-catalog.yaml')['x-error-catalog'];
}

test('the enum names exactly the catalogued codes', function () {
    $enum = array_map(fn (ErrorCode $code): string => $code->value, ErrorCode::cases());

    expect($enum)->toEqualCanonicalizing(array_keys(errorCatalogue()));
});

test('each code carries the catalogue status, title and retry class', function () {
    foreach (errorCatalogue() as $name => $entry) {
        $code = ErrorCode::from($name);

        expect($code->status())->toBe($entry['status'], "{$name} status")
            ->and($code->title())->toBe($entry['title'], "{$name} title")
            ->and($code->retryable())->toBe($entry['retryable'], "{$name} retryable")
            ->and($code->isFieldLevelOnly())->toBe($entry['level'] === ['field'], "{$name} level");
    }
});

test('every response-level code has a detail sentence in both languages', function () {
    foreach (['en', 'ar'] as $locale) {
        $lines = require dirname(__DIR__, 3)."/lang/{$locale}/problems.php";

        foreach (errorCatalogue() as $name => $entry) {
            $present = in_array('response', $entry['level'], true)
                ? isset($lines[$name])
                : isset($lines['fields'][$name]);

            expect($present)->toBeTrue("lang/{$locale}/problems.php lacks {$name}");
        }
    }
});
