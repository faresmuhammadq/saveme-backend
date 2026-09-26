<?php

use App\Modules\Identity\Infrastructure\Database\Factories\DeviceSessionFactory;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use Illuminate\Support\Facades\Route;

/*
 * App users authenticate only with a device-session bearer credential; the
 * web guard carries no user provider, so no app user can hold a browser
 * session (P-08 rule 4, P-18 rule 4; spec 007, R-1, R-20).
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:api'])->prefix('api/v1')->get('_whoami', fn () => response()->json(['id' => auth()->id()]));
});

test('the guards are configured bearer-only', function () {
    expect(config('auth.defaults.guard'))->toBe('api')
        ->and(config('auth.guards.api.driver'))->toBe('sanctum')
        ->and(config('auth.guards.web'))->not->toHaveKey('provider')
        ->and(config('sanctum.guard'))->toBe([])
        ->and(config('sanctum.stateful'))->toBe([]);
});

test('a bearer credential authenticates; its absence does not', function () {
    $session = DeviceSession::factory()->create();

    $this->withHeaders(jsonHeaders(['Authorization' => 'Bearer '.DeviceSessionFactory::credentialFor($session)]))
        ->get('/api/v1/_whoami')
        ->assertOk()
        ->assertJsonPath('id', $session->user_id);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->withHeaders(jsonHeaders())->get('/api/v1/_whoami')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('a malformed or unknown credential is simply unauthenticated', function (string $credential) {
    $this->withHeaders(jsonHeaders(['Authorization' => "Bearer {$credential}"]))->get('/api/v1/_whoami')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
})->with(['garbage', 'not-a-uuid|secret', '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70|wrong']);
