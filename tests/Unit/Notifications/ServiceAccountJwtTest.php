<?php

use App\Modules\Notifications\Infrastructure\Push\Jwt;
use App\Modules\Notifications\Infrastructure\Push\ServiceAccountKey;

function throwawayKey(): OpenSSLAsymmetricKey
{
    return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
}

function decodePart(string $part): array
{
    return json_decode(base64_decode(strtr($part, '-_', '+/')), true);
}

test('an RS256 token verifies with the public key and carries its header and claims', function () {
    $key = throwawayKey();
    $details = openssl_pkey_get_details($key);

    $jwt = Jwt::rs256(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'key-1'], ['iss' => 'robot@example.test', 'scope' => 'x', 'exp' => 1234], $key);
    [$header, $claims, $signature] = explode('.', $jwt);

    expect(decodePart($header))->toBe(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'key-1'])
        ->and(decodePart($claims))->toBe(['iss' => 'robot@example.test', 'scope' => 'x', 'exp' => 1234])
        ->and(openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/')), $details['key'], OPENSSL_ALGO_SHA256))->toBe(1);
});

test('the token is base64url without padding or reserved characters', function () {
    $jwt = Jwt::rs256(['alg' => 'RS256'], ['a' => str_repeat('?>', 40)], throwawayKey());

    expect($jwt)->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/');
});

test('a tampered claim no longer verifies', function () {
    $key = throwawayKey();
    $details = openssl_pkey_get_details($key);
    [$header, , $signature] = explode('.', Jwt::rs256(['alg' => 'RS256'], ['n' => 1], $key));
    $forged = Jwt::base64Url('{"n":2}');

    expect(openssl_verify("{$header}.{$forged}", base64_decode(strtr($signature, '-_', '+/')), $details['key'], OPENSSL_ALGO_SHA256))->toBe(0);
});

test('a service-account key is read from base64 JSON and keeps the secret out of dumps', function () {
    openssl_pkey_export(throwawayKey(), $pem);
    $json = json_encode(['client_email' => 'robot@example.test', 'private_key' => $pem, 'private_key_id' => 'kid-1']);

    $key = ServiceAccountKey::from(base64_encode($json), 'https://oauth2.example.test/token');

    expect($key->clientEmail)->toBe('robot@example.test')
        ->and($key->keyId)->toBe('kid-1')
        ->and($key->tokenUri)->toBe('https://oauth2.example.test/token')
        ->and($key->privateKey())->toBeInstanceOf(OpenSSLAsymmetricKey::class)
        ->and(print_r($key, true))->not->toContain('PRIVATE KEY');
});

test('a key that is unreadable or incomplete is refused without echoing its content', function () {
    $incomplete = base64_encode(json_encode(['client_email' => 'robot@example.test', 'private_key' => 'SECRET-MATERIAL']));

    expect(fn () => ServiceAccountKey::from($incomplete, 'u'))->toThrow(RuntimeException::class, 'lack [private_key_id]')
        ->and(fn () => ServiceAccountKey::from('not json at all', 'u'))->toThrow(RuntimeException::class);

    try {
        ServiceAccountKey::from($incomplete, 'u');
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->not->toContain('SECRET-MATERIAL');
    }
});
