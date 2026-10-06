<?php

use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Delivery\PushDestination;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\Priority;
use App\Modules\Notifications\Domain\Messages\PushMessage;
use App\Modules\Notifications\Infrastructure\Push\PushGatewayFactory;
use App\Modules\Notifications\Infrastructure\Push\ServiceAccountTokenSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const FCM_SECRET_TOKEN = 'device-token-SECRET-123';

function fcmConfigured(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    config([
        'notifications.push.driver' => 'fcm',
        'notifications.push.fcm.project_id' => 'saveme-demo',
        'notifications.push.fcm.credentials' => base64_encode(json_encode([
            'client_email' => 'robot@saveme-demo.iam.example.test',
            'private_key' => $pem,
            'private_key_id' => 'kid-1',
            'token_uri' => 'https://oauth2.example.test/token',
        ])),
    ]);
    Cache::forget(ServiceAccountTokenSource::CACHE_KEY);
}

function fcmMessage(): PushMessage
{
    $id = '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70';

    return new PushMessage(
        MessageType::OccurrenceDue,
        $id,
        'occurrence:'.$id,
        'Something is due today',
        'A recurring item needs your confirmation.',
        ['schema' => '1', 'type' => 'occurrence_due', 'category' => 'recurring_due', 'notification_id' => $id, 'wallet_id' => $id, 'occurrence_id' => $id],
        'recurring_due',
        Priority::High,
    );
}

function fcmDestination(): PushDestination
{
    return new PushDestination('registration-1', FCM_SECRET_TOKEN);
}

function tokenEndpoint(): string
{
    return 'oauth2.example.test/token';
}

test('one access token is minted for two sends, and the send body has the frozen shape', function () {
    fcmConfigured();
    Http::fake([
        tokenEndpoint() => Http::response(['access_token' => 'access-1', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/saveme-demo/messages/1']),
    ]);

    $gateway = PushGatewayFactory::make('fcm');

    expect($gateway->send(fcmMessage(), fcmDestination())->isAccepted())->toBeTrue()
        ->and($gateway->send(fcmMessage(), fcmDestination())->isAccepted())->toBeTrue();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.example.test')
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
        && substr_count((string) $request['assertion'], '.') === 2);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'fcm.googleapis.com')) {
            return false;
        }

        $message = $request['message'];

        return $request->url() === 'https://fcm.googleapis.com/v1/projects/saveme-demo/messages:send'
            && $request->hasHeader('Authorization', 'Bearer access-1')
            && $message['token'] === FCM_SECRET_TOKEN
            && $message['notification'] === ['title' => 'Something is due today', 'body' => 'A recurring item needs your confirmation.']
            && $message['data']['type'] === 'occurrence_due'
            && $message['android']['collapse_key'] === 'occurrence:0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70'
            && $message['android']['priority'] === 'HIGH'
            && $message['android']['ttl'] === '86400s'
            && $message['android']['notification'] === ['channel_id' => 'recurring_due', 'tag' => 'occurrence:0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70'];
    });
});

test('the access token is cached until five minutes before it expires', function () {
    fcmConfigured();
    Http::fake([
        tokenEndpoint() => Http::response(['access_token' => 'access-1', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response([]),
    ]);

    PushGatewayFactory::make('fcm')->send(fcmMessage(), fcmDestination());

    $this->travel(54)->minutes();
    expect(Cache::get(ServiceAccountTokenSource::CACHE_KEY))->toBe('access-1');

    $this->travel(2)->minutes();
    expect(Cache::get(ServiceAccountTokenSource::CACHE_KEY))->toBeNull();
});

test('a 401 forgets the token, mints another and sends once more', function () {
    fcmConfigured();
    Http::fake([
        tokenEndpoint() => Http::sequence()
            ->push(['access_token' => 'stale', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401)
            ->push(['name' => 'projects/saveme-demo/messages/2']),
    ]);

    $outcome = PushGatewayFactory::make('fcm')->send(fcmMessage(), fcmDestination());

    expect($outcome->isAccepted())->toBeTrue();
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fcm.googleapis.com') && $request->hasHeader('Authorization', 'Bearer fresh'));
});

test('a second 401 is left to the job to retry', function () {
    fcmConfigured();
    Http::fake([
        tokenEndpoint() => Http::response(['access_token' => 'access', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401),
    ]);

    $outcome = PushGatewayFactory::make('fcm')->send(fcmMessage(), fcmDestination());

    expect($outcome->kind)->toBe(GatewayOutcome::RETRY_LATER);
});

test('an unregistered token is reported as invalid', function () {
    fcmConfigured();
    Http::fake([
        tokenEndpoint() => Http::response(['access_token' => 'access', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);

    expect(PushGatewayFactory::make('fcm')->send(fcmMessage(), fcmDestination())->isInvalidToken())->toBeTrue();
});

test('a dropped connection is retried, not failed', function () {
    fcmConfigured();
    Http::fake([
        tokenEndpoint() => Http::response(['access_token' => 'access', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28 for https://fcm.googleapis.com'),
    ]);

    expect(PushGatewayFactory::make('fcm')->send(fcmMessage(), fcmDestination())->isRetryable())->toBeTrue();
});

test('neither the token, the key, the assertion nor the access token reaches a log line', function () {
    fcmConfigured();
    $logs = captureLogs();
    Http::fake([
        tokenEndpoint() => Http::response(['access_token' => 'access-SECRET-xyz', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::sequence()->push([], 200)->push(['error' => ['status' => 'UNAVAILABLE']], 503),
    ]);

    $gateway = PushGatewayFactory::make('fcm');
    $gateway->send(fcmMessage(), fcmDestination());
    $gateway->send(fcmMessage(), fcmDestination());

    $everything = implode("\n", $logs->getArrayCopy());

    expect($everything)->not->toContain(FCM_SECRET_TOKEN)
        ->and($everything)->not->toContain('access-SECRET-xyz')
        ->and($everything)->not->toContain('PRIVATE KEY')
        ->and($everything)->not->toContain('eyJ');
});

test('a refused token endpoint is retried later, and the answer is never echoed', function () {
    fcmConfigured();
    $logs = captureLogs();
    Http::fake([tokenEndpoint() => Http::response(['error' => 'invalid_grant', 'error_description' => 'ASSERTION-LEAK'], 400)]);

    $outcome = PushGatewayFactory::make('fcm')->send(fcmMessage(), fcmDestination());

    expect($outcome->isRetryable())->toBeTrue()
        ->and($outcome->code)->toBe('TOKEN_UNAVAILABLE')
        ->and(implode("\n", $logs->getArrayCopy()))->not->toContain('ASSERTION-LEAK');
});
