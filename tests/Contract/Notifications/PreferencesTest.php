<?php

use App\Modules\Notifications\Infrastructure\Models\NotificationPreference;
use Illuminate\Testing\TestResponse;

/*
 * GET and PUT /me/notification-preferences (spec 023, US2) against
 * docs/openapi/paths/notifications.yaml.
 */

beforeEach(function () {
    person();
    $this->credential = signInAs()->json('data.credential');
});

function preferencesBody(array $changes = [], int $version = 1): array
{
    return array_replace_recursive([
        'version' => $version,
        'categories' => [
            'recurring_due' => true, 'budget_exceeded' => true, 'sharing' => true,
            'billing' => true, 'export_ready' => true, 'deletion_reminder' => true,
        ],
        'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '08:00'],
    ], $changes);
}

function getPreferences(string $credential): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->getJson('/api/v1/me/notification-preferences');
    test()->flushHeaders();

    return $response;
}

function putPreferences(string $credential, array $body): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->putJson('/api/v1/me/notification-preferences', $body);
    test()->flushHeaders();

    return $response;
}

test('getNotificationPreferences: 200 with the defaults at version 1', function () {
    $response = getPreferences($this->credential)
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data'))->toBe([
        'version' => 1,
        'categories' => ['recurring_due' => true, 'budget_exceeded' => true, 'sharing' => true, 'billing' => true, 'export_ready' => true, 'deletion_reminder' => true],
        'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '08:00'],
        'updated_at' => null,
    ])->and($response->getContent())->toContain('"meta":{}');
});

test('getNotificationPreferences: 401 and the shared refusals', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/me/notification-preferences')
        ->assertValidResponse(401)->assertJsonPath('code', 'UNAUTHENTICATED');

    assertSharedRefusals('GET', '/me/notification-preferences', [], $this->credential);
});

test('saveNotificationPreferences: 200, and the version moves up by one', function () {
    $body = preferencesBody(['categories' => ['budget_exceeded' => false], 'quiet_hours' => ['start' => '23:00', 'end' => '07:00']]);

    $saved = putPreferences($this->credential, $body)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.categories.budget_exceeded', false)
        ->assertJsonPath('data.quiet_hours.start', '23:00');

    expect($saved->json('data.updated_at'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/');

    getPreferences($this->credential)->assertValidResponse(200)->assertJsonPath('data.version', 2);
});

test('saveNotificationPreferences: an identical save is 200 and does not move the version', function () {
    $body = preferencesBody(['categories' => ['sharing' => false]]);
    putPreferences($this->credential, $body)->assertOk();

    putPreferences($this->credential, [...$body, 'version' => 2])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);
});

test('saveNotificationPreferences: a stale version is 409 carrying the stored preferences', function () {
    putPreferences($this->credential, preferencesBody(['categories' => ['sharing' => false]]))->assertOk();

    $conflict = putPreferences($this->credential, preferencesBody(['categories' => ['sharing' => true, 'billing' => false]]))
        ->assertValidRequest()
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.version', 2)
        ->assertJsonPath('current.categories.sharing', false)
        ->assertJsonPath('current.categories.billing', true);

    expect(NotificationPreference::query()->count())->toBe(1)
        ->and(NotificationPreference::query()->first()->billing)->toBeTrue();
});

test('saveNotificationPreferences: 422 for every documented validation fault', function (array $changes, string $pointer, string $code, ?array $remove = null) {
    $body = preferencesBody($changes);

    foreach ($remove ?? [] as $path) {
        Arr::forget($body, $path);
    }

    $response = putPreferences($this->credential, $body)
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(collect($response->json('errors'))->contains(fn (array $error): bool => $error['pointer'] === $pointer && $error['code'] === $code))
        ->toBeTrue(json_encode($response->json('errors')))
        ->and(NotificationPreference::query()->count())->toBe(0);
})->with([
    'a missing category' => [[], '/categories/billing', 'REQUIRED', ['categories.billing']],
    'an unknown category' => [['categories' => ['telepathy' => true]], '/categories/telepathy', 'UNKNOWN_FIELD'],
    'not on a quarter hour' => [['quiet_hours' => ['start' => '23:10']], '/quiet_hours/start', 'OUT_OF_RANGE'],
    'midnight spelt 24:00' => [['quiet_hours' => ['end' => '24:00']], '/quiet_hours/end', 'OUT_OF_RANGE'],
    'start equal to end' => [['quiet_hours' => ['start' => '08:00', 'end' => '08:00']], '/quiet_hours/end', 'OUT_OF_RANGE'],
    'a string boolean' => [['categories' => ['sharing' => 'true']], '/categories/sharing', 'INVALID_TYPE'],
    'a numeric boolean' => [['quiet_hours' => ['enabled' => 1]], '/quiet_hours/enabled', 'INVALID_TYPE'],
    'a time that is not a string' => [['quiet_hours' => ['start' => 2200]], '/quiet_hours/start', 'INVALID_TYPE'],
    'updated_at sent back' => [['updated_at' => '2026-10-06T10:00:00.000000Z'], '/updated_at', 'UNKNOWN_FIELD'],
    'a missing version' => [[], '/version', 'REQUIRED', ['version']],
    'a missing quiet_hours member' => [[], '/quiet_hours/end', 'REQUIRED', ['quiet_hours.end']],
    'a version of zero' => [['version' => 0], '/version', 'OUT_OF_RANGE'],
]);

test('saveNotificationPreferences: quiet hours may wrap midnight, or not, and any quarter hour is accepted', function () {
    putPreferences($this->credential, preferencesBody(['quiet_hours' => ['start' => '13:00', 'end' => '15:45']]))
        ->assertValidResponse(200)
        ->assertJsonPath('data.quiet_hours.end', '15:45');
});

test('saveNotificationPreferences: quiet hours can be turned off and keep their times', function () {
    putPreferences($this->credential, preferencesBody(['quiet_hours' => ['enabled' => false]]))
        ->assertValidResponse(200)
        ->assertJsonPath('data.quiet_hours', ['enabled' => false, 'start' => '22:00', 'end' => '08:00']);
});

test('saveNotificationPreferences: 401, and the shared refusals', function () {
    $this->withHeaders(jsonHeaders())->putJson('/api/v1/me/notification-preferences', preferencesBody())
        ->assertValidResponse(401);

    assertSharedRefusals('PUT', '/me/notification-preferences', preferencesBody(), $this->credential);
});

test('saveNotificationPreferences: 429 after 30 saves in an hour, a read is never limited', function () {
    foreach (range(1, 30) as $i) {
        putPreferences($this->credential, preferencesBody(version: $i === 1 ? 1 : 2, changes: ['categories' => ['sharing' => $i % 2 === 0]]));
    }

    putPreferences($this->credential, preferencesBody())
        ->assertValidResponse(429)
        ->assertJsonPath('code', 'RATE_LIMITED');

    getPreferences($this->credential)->assertOk();
});

test('preferences are not behind the verification gate', function () {
    person('unverified@example.test', verified: false);
    $credential = signInAs('unverified@example.test')->json('data.credential');

    putPreferences($credential, preferencesBody())->assertValidResponse(200);
});
