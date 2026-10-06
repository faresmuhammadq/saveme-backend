<?php

use App\Modules\Notifications\Application\DeviceService;
use App\Modules\Notifications\Domain\Ports\TokenLookup;
use App\Modules\Notifications\Domain\Ports\TokenSealer;
use App\Modules\Notifications\Infrastructure\Crypto\NotificationsKeyring;
use App\Modules\Notifications\Infrastructure\Crypto\NotificationsSealer;
use App\Modules\Notifications\Infrastructure\Models\PushRegistration;

const AT_REST_TOKEN = 'fcm-token-AT-REST-secret-value';

test('the stored value never contains the token and names its key', function () {
    $user = person();
    $sessionId = registerPhone($user, AT_REST_TOKEN);

    $row = PushRegistration::query()->where('session_id', $sessionId)->firstOrFail();

    expect($row->token_sealed)->not->toContain(AT_REST_TOKEN)
        ->and($row->token_sealed)->toStartWith('v1.n1.')
        ->and($row->token_lookup)->not->toContain(AT_REST_TOKEN)
        ->and($row->token_lookup)->toBe(app(TokenLookup::class)->for(AT_REST_TOKEN))
        ->and(json_encode($row->toArray()))->not->toContain(AT_REST_TOKEN)
        ->and(array_keys($row->toArray()))->not->toContain('token_sealed')
        ->and(array_keys($row->toArray()))->not->toContain('token_lookup');
});

test('the token opens only with the session it was sealed for', function () {
    $sealer = new NotificationsSealer(NotificationsKeyring::fromConfig());
    $sealed = $sealer->seal(AT_REST_TOKEN, 'session-a');

    expect($sealer->open($sealed, 'session-a'))->toBe(AT_REST_TOKEN)
        ->and(fn () => $sealer->open($sealed, 'session-b'))->toThrow(RuntimeException::class, 'failed authentication');
});

test('each seal uses a fresh nonce', function () {
    $sealer = new NotificationsSealer(NotificationsKeyring::fromConfig());

    expect($sealer->seal(AT_REST_TOKEN, 's'))->not->toBe($sealer->seal(AT_REST_TOKEN, 's'));
});

test('a value sealed under an old key still opens after the active key moves on', function () {
    $old = base64_encode(str_repeat('o', 32));
    $new = base64_encode(str_repeat('n', 32));

    $sealedUnderOld = (new NotificationsSealer(new NotificationsKeyring(['k0' => $old, 'k1' => $new], 'k0')))->seal(AT_REST_TOKEN, 's');
    $rotated = new NotificationsSealer(new NotificationsKeyring(['k0' => $old, 'k1' => $new], 'k1'));

    expect($sealedUnderOld)->toStartWith('v1.k0.')
        ->and($rotated->open($sealedUnderOld, 's'))->toBe(AT_REST_TOKEN)
        ->and($rotated->seal(AT_REST_TOKEN, 's'))->toStartWith('v1.k1.');
});

test('a refresh re-seals the token under the active key', function () {
    $user = person();
    $session = sessionIdFor($user);
    $old = base64_encode(str_repeat('o', 32));

    config(['notifications.keys' => [...config('notifications.keys'), 'k0' => $old], 'notifications.active_key' => 'k0']);
    app()->forgetInstance(TokenSealer::class);
    app(DeviceService::class)->register($user->id, $session, $session, AT_REST_TOKEN);
    expect(PushRegistration::query()->where('session_id', $session)->value('token_sealed'))->toStartWith('v1.k0.');

    config(['notifications.active_key' => 'n1']);
    app()->forgetInstance(TokenSealer::class);
    app(DeviceService::class)->register($user->id, $session, $session, AT_REST_TOKEN);

    expect(PushRegistration::query()->where('session_id', $session)->value('token_sealed'))->toStartWith('v1.n1.');
});
