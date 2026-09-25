<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test suites
|--------------------------------------------------------------------------
|
| Four suites, one per layer plus the architecture gates (spec 005):
|
|   Arch      no application, no database — boundaries, migrations, skeleton
|   Unit      domain rules, no framework, no database
|   Feature   application services against PostgreSQL, refreshed per test
|   Contract  HTTP endpoints against PostgreSQL, validated against the
|             OpenAPI definition in docs/openapi through Spectator
|
| Unit and Arch deliberately do not extend the application TestCase: a domain
| rule that needs the framework to be tested is in the wrong layer.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contract');

/*
|--------------------------------------------------------------------------
| Contract tests
|--------------------------------------------------------------------------
|
| Every Contract test validates against the published definition by default.
| An endpoint test then asserts conformance in one line:
|
|     $this->withHeaders(clientHeaders())
|         ->getJson('/api/v1/wallets/'.$id)
|         ->assertValidRequest()
|         ->assertValidResponse(200);
|
| Remember that an empty PHP array serialises to JSON [] — an empty envelope
| meta must be emitted as (object) [] to satisfy the contract.
|
*/

pest()->beforeEach(function () {
    Spectator\Spectator::using('openapi.yaml');
})->in('Contract');

/**
 * The headers every request to the API must carry (docs/api-conventions.md).
 *
 * @return array<string, string>
 */
function clientHeaders(string $platform = 'android', string $version = '1.0.0'): array
{
    return ['X-Client-Platform' => $platform, 'X-Client-Version' => $version];
}

/*
|--------------------------------------------------------------------------
| Back office (spec 006)
|--------------------------------------------------------------------------
|
| Primitives are anonymous Blade components; tests render them in a given
| locale and assert on the HTML. Vite is replaced so no build is needed.
|
*/

pest()->beforeEach(function () {
    $this->withoutVite();
})->in('Feature/Admin');

/**
 * Renders a Blade snippet using the back-office primitives in the given locale.
 *
 * @param  array<string, mixed>  $data
 */
function renderAdmin(string $blade, array $data = [], string $locale = 'en'): string
{
    app()->setLocale($locale);
    Carbon\Carbon::setLocale($locale);

    return (string) test()->blade($blade, $data);
}

/**
 * Renders a snippet inside the back-office HTML document, so lang and dir apply.
 *
 * @param  array<string, mixed>  $data
 */
function renderAdminDocument(string $blade, array $data = [], string $locale = 'en'): string
{
    return renderAdmin('<x-admin::layouts.document title="Test">'.$blade.'</x-admin::layouts.document>', $data, $locale);
}

expect()->extend('toUseWesternDigitsOnly', function () {
    expect(preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', (string) $this->value))
        ->toBe(0, 'P-14: output contains Eastern Arabic-Indic digits');

    return $this;
});

/**
 * Re-registers the back-office routes as they would be in the given
 * environment, replacing whatever the test application booted with.
 */
function registerAdminRoutesAs(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);

    $router = app('router');
    $kept = new RouteCollection;

    foreach ($router->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin')) {
            $kept->add($route);
        }
    }

    $router->setRoutes($kept);
    require app_path('Modules/Admin/Http/routes.php');
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

/**
 * @return list<string> URIs of registered back-office routes
 */
function adminRouteUris(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->map(fn (Route $route): string => $route->uri())
        ->filter(fn (string $uri): bool => $uri === 'admin' || str_starts_with($uri, 'admin/'))
        ->values()
        ->all();
}
