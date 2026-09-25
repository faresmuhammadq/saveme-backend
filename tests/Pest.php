<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
