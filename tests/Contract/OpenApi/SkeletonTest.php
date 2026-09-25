<?php

use Spectator\Spectator;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| The OpenAPI skeleton
|--------------------------------------------------------------------------
|
| docs/openapi is the source of truth for the HTTP contract (spec 003,
| FR-032). These checks were spec 003's quickstart; they now run on every push
| (spec 005, closing follow-up F-5).
|
*/

function openApiRoot(): string
{
    return dirname(__DIR__, 3).'/docs/openapi';
}

/** @return list<string> */
function openApiFiles(): array
{
    $root = openApiRoot();

    return array_merge(
        glob("{$root}/*.yaml") ?: [],
        glob("{$root}/components/*.yaml") ?: [],
        glob("{$root}/paths/*.yaml") ?: [],
    );
}

function relativeToRepo(string $file): string
{
    return str_replace(dirname(openApiRoot(), 2).'/', '', $file);
}

test('every file in the skeleton parses as YAML', function () {
    foreach (openApiFiles() as $file) {
        expect(fn () => Yaml::parseFile($file))->not->toThrow(Exception::class, relativeToRepo($file));
    }
});

test('every $ref resolves to a file and a key that exist', function () {
    $broken = [];

    foreach (openApiFiles() as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            if (preg_match('~^\s*#~', $line)) {
                continue;
            }
            if (! preg_match('~\$ref:\s*[\'"]?([^\'"\s]+)~', $line, $match)) {
                continue;
            }
            [$path, $fragment] = array_pad(explode('#', $match[1], 2), 2, '');
            $target = $path === '' ? $file : dirname($file).'/'.$path;
            $where = relativeToRepo($file).':'.($number + 1)."  {$match[1]}";

            if (! file_exists($target)) {
                $broken[] = "missing file  {$where}";

                continue;
            }
            $node = Yaml::parseFile($target);
            foreach (array_filter(explode('/', $fragment)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (! is_array($node) || ! array_key_exists($segment, $node)) {
                    $broken[] = "missing key   {$where}";

                    continue 2;
                }
                $node = $node[$segment];
            }
        }
    }

    expect($broken)->toBe([], "Unresolved references:\n  ".implode("\n  ", $broken));
});

test('the error catalogue enums and its metadata agree, and every entry is complete', function () {
    $catalogue = Yaml::parseFile(openApiRoot().'/error-catalog.yaml');
    $enum = array_merge(
        $catalogue['components']['schemas']['ErrorCode']['enum'],
        $catalogue['components']['schemas']['FieldErrorCode']['enum'],
    );
    $entries = $catalogue['x-error-catalog'];

    expect(array_values(array_diff($enum, array_keys($entries))))->toBe([], 'In an enum but not catalogued')
        ->and(array_values(array_diff(array_keys($entries), $enum)))->toBe([], 'Catalogued but in no enum');

    $incomplete = [];
    foreach ($entries as $code => $entry) {
        foreach (['status', 'level', 'title', 'meaning', 'retryable', 'since'] as $field) {
            if (! isset($entry[$field])) {
                $incomplete[] = "{$code} lacks {$field}";
            }
        }
        if (isset($entry['retryable']) && ! in_array($entry['retryable'], ['automatic', 'after-change', 'never'], true)) {
            $incomplete[] = "{$code} has retryable '{$entry['retryable']}'";
        }
    }

    expect($incomplete)->toBe([], implode("\n", $incomplete));
});

test('every error code a response names exists in the catalogue', function () {
    $catalogue = Yaml::parseFile(openApiRoot().'/error-catalog.yaml');
    $known = array_keys($catalogue['x-error-catalog']);
    $unknown = [];

    $files = [openApiRoot().'/components/responses.yaml', ...(glob(openApiRoot().'/paths/*.yaml') ?: [])];
    foreach ($files as $file) {
        preg_match_all('/Codes?:\s*([A-Z0-9_,\s()a-z-]+?)(?:\.|$)/m', file_get_contents($file) ?: '', $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('/\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b|\b(?:UNAUTHENTICATED|FORBIDDEN)\b/', $block, $codes);
            foreach ($codes[0] as $code) {
                if (! in_array($code, $known, true)) {
                    $unknown[] = relativeToRepo($file).": {$code}";
                }
            }
        }
    }

    expect(array_values(array_unique($unknown)))->toBe([], "Codes named outside the catalogue — add them to error-catalog.yaml:\n  ".implode("\n  ", $unknown));
});

test('path files reference shared problem responses rather than defining them', function () {
    $inline = [];

    foreach (glob(openApiRoot().'/paths/*.yaml') ?: [] as $file) {
        $document = Yaml::parseFile($file);
        foreach ($document as $path => $operations) {
            if ($path === 'components' || ! is_array($operations)) {
                continue;
            }
            foreach ($operations as $verb => $operation) {
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if ((int) $status >= 400 && ! isset($response['$ref'])) {
                        $inline[] = relativeToRepo($file).": {$verb} {$path} {$status}";
                    }
                }
            }
        }
    }

    expect($inline)->toBe([], "Problem responses must \$ref components/responses.yaml:\n  ".implode("\n  ", $inline));
});

test('the root document defines nothing inline', function () {
    $root = Yaml::parseFile(openApiRoot().'/openapi.yaml');
    $components = array_diff(array_keys($root['components'] ?? []), ['securitySchemes']);

    expect($components)->toBe([], 'openapi.yaml may declare only securitySchemes under components');

    foreach ($root['paths'] ?? [] as $path => $item) {
        expect(array_keys($item))->toBe(['$ref'], "openapi.yaml path {$path} must be a \$ref into paths/");
    }
});

test('no banned field name appears', function () {
    $banned = '/\b(amount_cents|is_deleted|space_id|updated_time|total_count|cached_balance)\b/';
    $hits = [];

    foreach (openApiFiles() as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            if (preg_match($banned, $line, $match)) {
                $hits[] = relativeToRepo($file).':'.($number + 1)."  {$match[1]}";
            }
        }
    }

    expect($hits)->toBe([], "Banned field names (see docs/glossary.md field conventions):\n  ".implode("\n  ", $hits));
});

test('the validator loads the published document', function () {
    Spectator::using('openapi.yaml');

    expect(Spectator::getSpec())->not->toBeNull();
});

test('no catalogue code is newer than the document that publishes it', function () {
    $version = Yaml::parseFile(openApiRoot().'/openapi.yaml')['info']['version'];
    $newer = [];

    foreach (Yaml::parseFile(openApiRoot().'/error-catalog.yaml')['x-error-catalog'] as $code => $entry) {
        if (version_compare(ltrim($entry['since'], 'v'), ltrim($version, 'v'), '>')) {
            $newer[] = "{$code} since {$entry['since']}";
        }
    }

    expect($newer)->toBe([], "info.version is {$version}; bump it in the change that adds these codes:\n  ".implode("\n  ", $newer));
});

test('every path file is reachable from the index', function () {
    $index = file_get_contents(openApiRoot().'/openapi.yaml') ?: '';
    $orphans = [];

    foreach (glob(openApiRoot().'/paths/*.yaml') ?: [] as $file) {
        $name = basename($file);
        // Files starting with an underscore are templates, never indexed.
        if (! str_starts_with($name, '_') && ! str_contains($index, "./paths/{$name}#")) {
            $orphans[] = $name;
        }
    }

    expect($orphans)->toBe([], "Path files not referenced from openapi.yaml — their endpoints are invisible to clients:\n  ".implode("\n  ", $orphans));
});
