<?php

use App\Modules\Billing\Infrastructure\GooglePlay\Jwt;

/*
 * The in-house RS256 service-account assertion (spec 024, FR-059): signed
 * with openssl_sign, base64url without padding, verifiable with the public key.
 */

test('an RS256 token verifies with the public key and carries the claims it was given', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $public = openssl_pkey_get_details($key)['key'];

    $jwt = Jwt::rs256(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'abc'], ['iss' => 'svc@example.test', 'scope' => 'https://www.googleapis.com/auth/androidpublisher', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => 1000, 'exp' => 4600], $key);
    [$header, $claims, $signature] = explode('.', $jwt);

    expect(openssl_verify($header.'.'.$claims, Jwt::decodeBase64Url($signature), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(json_decode(Jwt::decodeBase64Url($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'abc'])
        ->and(json_decode(Jwt::decodeBase64Url($claims), true))->toMatchArray(['iss' => 'svc@example.test', 'scope' => 'https://www.googleapis.com/auth/androidpublisher', 'iat' => 1000, 'exp' => 4600])
        ->and($jwt)->not->toContain('=')->not->toContain('+')->not->toContain('/');
});

test('a tampered token no longer verifies', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $public = openssl_pkey_get_details($key)['key'];
    [$header, , $signature] = explode('.', Jwt::rs256(['alg' => 'RS256'], ['iat' => 1], $key));
    $forgedClaims = Jwt::base64Url('{"iat":2}');

    expect(openssl_verify($header.'.'.$forgedClaims, Jwt::decodeBase64Url($signature), $public, OPENSSL_ALGO_SHA256))->toBe(0);
});

test('base64url decoding is strict', function () {
    expect(Jwt::decodeBase64Url('aGVsbG8'))->toBe('hello')
        ->and(Jwt::decodeBase64Url('aGVsbG8='))->toBeNull()
        ->and(Jwt::decodeBase64Url('a+b/'))->toBeNull()
        ->and(Jwt::decodeBase64Url(''))->toBeNull();
});
