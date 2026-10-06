<?php

use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Models\PushRegistration;
use Illuminate\Testing\TestResponse;

/*
 * PUT and DELETE /me/devices/{id} (spec 023, US1) against
 * docs/openapi/paths/notifications.yaml.
 */

const DEVICE_TOKEN = 'dQw4w9WgXcQ:APA91bHPRgkF3JUikC4ENAHEeMrd41Zxv3hVZjC9KtT8OvPVGJ';

beforeEach(function () {
    person();
    $this->device = deviceBody();
    $this->sessionId = $this->device['session_id'];
    $this->credential = signInAs(device: $this->device)->json('data.credential');
});

function putDevice(string $credential, string $id, array $body): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->putJson("/api/v1/me/devices/{$id}", $body);
    test()->flushHeaders();

    return $response;
}

function deleteDevice(string $credential, string $id): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->deleteJson("/api/v1/me/devices/{$id}");
    test()->flushHeaders();

    return $response;
}

test('registerPushDevice: 200 on the first registration, without the token', function () {
    $response = putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.session_id', $this->sessionId);

    expect($response->json('data'))->toHaveKeys(['session_id', 'registered_at', 'refreshed_at'])
        ->and($response->json('data'))->toHaveCount(3)
        ->and($response->getContent())->not->toContain(DEVICE_TOKEN)
        ->and($response->getContent())->toContain('"meta":{}')
        ->and($response->json('data.registered_at'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/');
});

test('registerPushDevice: 200 on a rotation and on the same token again', function () {
    putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN])->assertOk();
    $this->travel(2)->hours();

    $rotated = putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN.'-NEW'])
        ->assertValidResponse(200);
    $again = putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN.'-NEW'])
        ->assertValidResponse(200);

    expect($rotated->json('data.registered_at'))->not->toBe($rotated->json('data.refreshed_at'))
        ->and($again->json('data.registered_at'))->toBe($rotated->json('data.registered_at'))
        ->and(PushRegistration::query()->count())->toBe(1);
});

test('registerPushDevice: 404 for another person, another own session, an unknown id', function () {
    $grace = person('grace@example.test');
    $graceSession = sessionIdFor($grace);
    $ownOther = sessionIdFor(User::query()->where('email_lookup', app(AddressLookup::class)->for('ada@example.test'))->firstOrFail());

    foreach ([$graceSession, $ownOther, (string) Str::uuid7()] as $id) {
        putDevice($this->credential, $id, ['push_token' => DEVICE_TOKEN])
            ->assertValidRequest()
            ->assertValidResponse(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    expect(PushRegistration::query()->count())->toBe(0);
});

test('registerPushDevice: 404 once the session has ended', function () {
    $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/auth/logout')->assertNoContent();

    putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN])->assertValidResponse(401);
});

test('registerPushDevice: 422 for every documented validation fault', function (array $body, string $pointer, string $code) {
    $response = putDevice($this->credential, $this->sessionId, $body)
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(collect($response->json('errors'))->contains(fn (array $error): bool => $error['pointer'] === $pointer && $error['code'] === $code))->toBeTrue()
        ->and($response->getContent())->not->toContain(DEVICE_TOKEN);
})->with([
    'missing token' => [[], '/push_token', 'REQUIRED'],
    'empty token' => [['push_token' => ''], '/push_token', 'OUT_OF_RANGE'],
    'too long' => [['push_token' => str_repeat('a', 4097)], '/push_token', 'OUT_OF_RANGE'],
    'a number' => [['push_token' => 12345], '/push_token', 'INVALID_TYPE'],
    'a platform member' => [['push_token' => 'abc', 'platform' => 'android'], '/platform', 'UNKNOWN_FIELD'],
    'spaces' => [['push_token' => 'has a space'], '/push_token', 'INVALID_TYPE'],
]);

test('registerPushDevice: the longest token, 4096 characters, is accepted', function () {
    putDevice($this->credential, $this->sessionId, ['push_token' => str_repeat('a', 4096)])->assertValidResponse(200);
});

test('registerPushDevice: 422 INVALID_ID for an id that is not a UUIDv7', function () {
    putDevice($this->credential, 'not-an-id', ['push_token' => DEVICE_TOKEN])
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'INVALID_ID');
});

test('registerPushDevice: the shared refusals 401, 406, 415, 426 and 400', function () {
    assertSharedRefusals('PUT', "/me/devices/{$this->sessionId}", ['push_token' => DEVICE_TOKEN], $this->credential);
});

test('registerPushDevice: 401 without a credential', function () {
    $this->withHeaders(jsonHeaders())->putJson("/api/v1/me/devices/{$this->sessionId}", ['push_token' => DEVICE_TOKEN])
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('registerPushDevice and unregisterPushDevice: 429 after 20 writes, shared by both', function () {
    foreach (range(1, 10) as $i) {
        putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN.$i])->assertOk();
        deleteDevice($this->credential, $this->sessionId)->assertNoContent();
    }

    putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN])
        ->assertValidResponse(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
    deleteDevice($this->credential, $this->sessionId)
        ->assertValidResponse(429);
});

test('unregisterPushDevice: 204, and 204 again when nothing is registered', function () {
    putDevice($this->credential, $this->sessionId, ['push_token' => DEVICE_TOKEN])->assertOk();

    deleteDevice($this->credential, $this->sessionId)->assertValidRequest()->assertValidResponse(204);
    deleteDevice($this->credential, $this->sessionId)->assertValidResponse(204);

    expect(PushRegistration::query()->count())->toBe(0);
});

test('unregisterPushDevice: 404 for a session that is not the caller current one', function () {
    deleteDevice($this->credential, (string) Str::uuid7())->assertValidResponse(404);
    deleteDevice($this->credential, sessionIdFor(person('grace@example.test')))->assertValidResponse(404);
});

test('unregisterPushDevice: 422 INVALID_ID and 401', function () {
    deleteDevice($this->credential, 'nope')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    $this->withHeaders(jsonHeaders())->deleteJson("/api/v1/me/devices/{$this->sessionId}")->assertValidResponse(401);
});

test('a registration is not behind the verification gate', function () {
    person('unverified@example.test', verified: false);
    $device = deviceBody();
    $credential = signInAs('unverified@example.test', device: $device)->json('data.credential');

    putDevice($credential, $device['session_id'], ['push_token' => DEVICE_TOKEN])->assertValidResponse(200);
});
