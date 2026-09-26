<?php

use App\Http\Api\Envelope;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Contract\Api\Support\ProbeRequest;
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

/*
|--------------------------------------------------------------------------
| API runtime (spec 007)
|--------------------------------------------------------------------------
|
| Fixture routes that run through the real `api` middleware group but are not
| part of the published contract, so runtime tests assert on raw JSON and
| headers rather than through Spectator.
|
*/

function registerApiProbes(): void
{
    Illuminate\Support\Facades\Route::middleware('api')->prefix('api/v1')->group(function (): void {
        Illuminate\Support\Facades\Route::get('_probe', fn () => Envelope::ok(['ok' => true]));
        Illuminate\Support\Facades\Route::post('_probe', fn (ProbeRequest $request) => Envelope::ok($request->validated()));
        Illuminate\Support\Facades\Route::get('_boom', fn () => throw new RuntimeException('secret stack detail'));
        Illuminate\Support\Facades\Route::get('_throttled', fn () => Envelope::ok(['ok' => true]))->middleware('throttle:2,1');
    });
}

/**
 * The headers a JSON client sends, plus the client headers.
 *
 * @return array<string, string>
 */
function jsonHeaders(array $extra = []): array
{
    // Each call is judged afresh, as in production: no user cached on the guard.
    if (function_exists('app') && app()->bound('auth')) {
        app('auth')->forgetGuards();
    }

    return [...clientHeaders(), 'Accept' => 'application/json', ...$extra];
}

expect()->extend('toBeUuidV7', function () {
    expect($this->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Identity (spec 007)
|--------------------------------------------------------------------------
*/

/**
 * A sign-in device description with a fresh session id.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function deviceBody(array $overrides = []): array
{
    return [
        'session_id' => (string) Str::uuid7(),
        'device_id' => 'install-'.Str::lower(Str::random(10)),
        'name' => 'Pixel 8',
        'platform' => 'android',
        'app_version' => '1.0.0',
        ...$overrides,
    ];
}

/**
 * A registered, verified person with a known address and password.
 */
function person(string $email = 'ada@example.test', string $password = 'correct horse', bool $verified = true): User
{
    $factory = User::factory()->withEmail($email)->withPassword($password);
    $user = ($verified ? $factory->verified() : $factory->unverified())->create();
    Profile::factory()->create(['user_id' => $user->id]);

    return $user;
}

/**
 * Signs in through the API and returns the response.
 *
 * @param  array<string, string>|null  $device
 */
function signInAs(string $email = 'ada@example.test', string $password = 'correct horse', ?array $device = null): TestResponse
{
    $response = test()->withHeaders(jsonHeaders())->postJson('/api/v1/auth/login', [
        'email' => $email, 'password' => $password, 'device' => $device ?? deviceBody(),
    ]);
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return $response;
}

/**
 * Headers for an authenticated call with a credential; forgets the guard so
 * each call is judged afresh, as in production.
 *
 * @return array<string, string>
 */
function asCredential(string $credential): array
{
    app('auth')->forgetGuards();

    return jsonHeaders(['Authorization' => 'Bearer '.$credential]);
}

/**
 * Issues a live link of the given kind for a user, returning its secret.
 */
function linkFor(string $userId, LinkKind $kind): string
{
    return app(LinkRepository::class)->issue($userId, $kind);
}

/**
 * Spectator 3 cannot validate a request carrying the shared deepObject
 * `filter[...]` parameter: its caster reads `properties` on a schema that has
 * only `additionalProperties`, and the warning becomes an exception. Tests of
 * filtered requests switch it off and assert on status and code directly.
 * Remove when Spectator handles additionalProperties-only objects.
 */
function withoutSpectatorRequestValidation(): void
{
    Spectator\Spectator::reset();
}

/**
 * Calls POST /auth/change-password with a credential.
 *
 * @param  array<string, string>  $body
 */
function changePassword(string $credential, array $body): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->postJson('/api/v1/auth/change-password', $body);
    test()->flushHeaders();

    return $response;
}
