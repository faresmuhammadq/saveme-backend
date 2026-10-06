<?php

use App\Modules\Notifications\Domain\Delivery\PushGateway;
use App\Modules\Notifications\Domain\Ports\TokenLookup;
use App\Modules\Notifications\Domain\Ports\TokenSealer;
use App\Modules\Notifications\Infrastructure\Crypto\HmacTokenLookup;
use App\Modules\Notifications\Infrastructure\NotificationsPersistenceServiceProvider;
use App\Modules\Notifications\Infrastructure\Push\DisabledPushGateway;
use App\Modules\Notifications\Infrastructure\Push\FcmPushGateway;
use App\Modules\Notifications\Infrastructure\Push\LogPushGateway;

afterEach(function () {
    app()->detectEnvironment(fn (): string => 'testing');
});

function bootNotificationsIn(string $environment, string $driver): void
{
    app()->detectEnvironment(fn (): string => $environment);
    config(['notifications.push.driver' => $driver]);

    (new NotificationsPersistenceServiceProvider(app()))->boot();
}

function realGateway(string $driver): PushGateway
{
    config(['notifications.push.driver' => $driver]);
    app()->forgetInstance(PushGateway::class);

    return app(PushGateway::class);
}

test('each driver binds its own gateway', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    config([
        'notifications.push.fcm.project_id' => 'demo',
        'notifications.push.fcm.credentials' => base64_encode(json_encode(['client_email' => 'r@e.test', 'private_key' => $pem, 'private_key_id' => 'k'])),
    ]);

    expect(realGateway('log'))->toBeInstanceOf(LogPushGateway::class)
        ->and(realGateway('disabled'))->toBeInstanceOf(DisabledPushGateway::class)
        ->and(realGateway('fcm'))->toBeInstanceOf(FcmPushGateway::class);
});

test('only the disabled gateway claims not to deliver', function () {
    expect(realGateway('disabled')->delivers())->toBeFalse()
        ->and(realGateway('log')->delivers())->toBeTrue();
});

test('the log gateway is refused outside local and testing', function (string $environment) {
    expect(fn () => bootNotificationsIn($environment, 'log'))
        ->toThrow(RuntimeException::class, 'refused outside local and testing');
})->with(['production', 'staging']);

test('the log gateway boots in local and testing', function (string $environment) {
    bootNotificationsIn($environment, 'log');

    expect(true)->toBeTrue();
})->with(['local', 'testing']);

test('the disabled gateway boots anywhere', function (string $environment) {
    bootNotificationsIn($environment, 'disabled');

    expect(true)->toBeTrue();
})->with(['production', 'local', 'testing']);

test('the fcm gateway is refused without a project id or a readable key', function () {
    config(['notifications.push.fcm.project_id' => null, 'notifications.push.fcm.credentials' => null]);
    expect(fn () => bootNotificationsIn('production', 'fcm'))->toThrow(RuntimeException::class, 'NOTIFICATIONS_FCM_PROJECT_ID');

    config(['notifications.push.fcm.project_id' => 'demo', 'notifications.push.fcm.credentials' => '/no/such/file.json']);
    expect(fn () => bootNotificationsIn('production', 'fcm'))->toThrow(RuntimeException::class, 'not readable');

    config(['notifications.push.fcm.credentials' => base64_encode(json_encode(['client_email' => 'r@e.test']))]);
    expect(fn () => bootNotificationsIn('production', 'fcm'))->toThrow(RuntimeException::class, 'lack');
});

test('an unknown driver is refused', function () {
    expect(fn () => bootNotificationsIn('production', 'sms'))->toThrow(RuntimeException::class, 'Unknown push driver');
});

test('the sealing and lookup keys are required wherever a token is sealed', function () {
    config(['notifications.active_key' => 'missing']);
    app()->forgetInstance(TokenSealer::class);
    expect(fn () => app(TokenSealer::class)->seal('token', 'session'))->toThrow(RuntimeException::class, 'NOTIFICATIONS_ACTIVE_KEY');

    config(['notifications.active_key' => 'n1', 'notifications.keys' => ['n1' => base64_encode('too short')]]);
    app()->forgetInstance(TokenSealer::class);
    expect(fn () => app(TokenSealer::class)->seal('token', 'session'))->toThrow(RuntimeException::class, 'not 32 bytes');

    config(['notifications.lookup_key' => base64_encode('short')]);
    expect(fn () => app(TokenLookup::class)->for('token'))->toThrow(RuntimeException::class, 'NOTIFICATIONS_LOOKUP_KEY')
        ->and(HmacTokenLookup::class)->toBeString();
});
