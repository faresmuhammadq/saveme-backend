<?php

/*
 * Every request names its client and version (docs/api-conventions.md).
 */

beforeEach(fn () => registerApiProbes());

test('missing client headers are CLIENT_VERSION_REQUIRED', function () {
    $this->getJson('/api/v1/_probe')->assertStatus(400)->assertJsonPath('code', 'CLIENT_VERSION_REQUIRED');
});

test('a malformed version or unknown platform is CLIENT_VERSION_INVALID', function (string $platform, string $version) {
    $this->withHeaders(['X-Client-Platform' => $platform, 'X-Client-Version' => $version])->getJson('/api/v1/_probe')
        ->assertStatus(400)
        ->assertJsonPath('code', 'CLIENT_VERSION_INVALID');
})->with([['android', 'abc'], ['ios', '1.0.0']]);

test('a version below the floor is 426 and names the minimum', function () {
    config(['api.minimum_client_version.android' => '1.2.0']);

    $this->withHeaders(clientHeaders(version: '1.1.9'))->getJson('/api/v1/_probe')
        ->assertStatus(426)
        ->assertJsonPath('code', 'CLIENT_VERSION_UNSUPPORTED')
        ->assertJsonPath('minimum_version', '1.2.0');
});
