<?php

use App\Modules\Identity\Domain\Account\LinkKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * No endpoint returns a password hash, a credential hash, a link hash or key
 * material; the credential appears only in the sign-in response (security
 * baseline A5; spec 007, FR-071).
 */

test('a full walk through the endpoints leaks no stored secret', function () {
    Mail::fake();
    /** @var list<TestResponse> $responses */
    $responses = [];
    $send = function (string $method, string $uri, array $body = [], ?string $credential = null) use (&$responses): TestResponse {
        $headers = $credential === null ? jsonHeaders() : asCredential($credential);
        $response = test()->withHeaders($headers)->json($method, $uri, $body);
        test()->flushHeaders();

        return $responses[] = $response;
    };

    $send('POST', '/api/v1/auth/register', ['email' => 'ada@example.test', 'password' => 'correct horse']);
    $login = $send('POST', '/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'correct horse', 'device' => deviceBody()]);
    $credential = $login->json('data.credential');
    $userId = $login->json('data.account.id');
    $send('GET', '/api/v1/auth/sessions', [], $credential);
    $send('POST', '/api/v1/auth/verify-email', ['token' => linkFor($userId, LinkKind::VerifyAddress)]);
    $send('POST', '/api/v1/auth/forgot-password', ['email' => 'ada@example.test']);
    $send('POST', '/api/v1/auth/change-password', ['current_password' => 'correct horse', 'new_password' => 'battery staple'], $credential);
    $send('POST', '/api/v1/account/deletion-request', ['password' => 'battery staple'], $credential);
    $send('DELETE', '/api/v1/account/deletion-request', [], $credential);

    $stored = array_filter([
        ...DB::table('users')->pluck('password_hash')->all(),
        ...DB::table('users')->pluck('email_lookup')->all(),
        ...DB::table('users')->pluck('email_ciphertext')->all(),
        ...DB::table('device_sessions')->pluck('token')->all(),
        ...DB::table('identity_links')->pluck('secret_hash')->all(),
        ...DB::table('user_keys')->pluck('wrapped_key')->all(),
    ]);

    foreach ($responses as $index => $response) {
        $body = (string) $response->getContent();

        foreach (['password_hash', 'secret_hash', 'email_ciphertext', 'email_lookup', 'wrapped_key', '"token"'] as $member) {
            expect($body)->not->toContain($member);
        }
        foreach ($stored as $secret) {
            expect($body)->not->toContain((string) $secret);
        }
        if ($response !== $login) {
            expect($body)->not->toContain('credential')->not->toContain(explode('|', $credential)[1]);
        }
    }
});
