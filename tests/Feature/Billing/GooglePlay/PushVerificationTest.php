<?php

use App\Modules\Billing\Infrastructure\GooglePlay\CertificatesUnavailable;
use App\Modules\Billing\Infrastructure\GooglePlay\PushTokenVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlayPushSigner;

/*
 * US4: only Google can push (spec 024, FR-030, FR-031; security baseline,
 * Messages from the payment provider). The verifier checks RS256 against
 * Google's own certificates, then issuer, audience, sender, time window.
 */

beforeEach(function () {
    $this->signer = PlayPushSigner::make();
    $this->signer->serveCertificates();
    $this->verifier = app(PushTokenVerifier::class);
});

/**
 * Replaces what Http::fake already answers: the first matching stub wins.
 */
function refakeCertificates($response): void
{
    Http::swap(new Factory);
    Http::fake([PlayPushSigner::CERTS_URL => $response]);
}

function pushFailure(?string $token): ?string
{
    return app(PushTokenVerifier::class)->failure($token);
}

function certificateFetches(): int
{
    return Http::recorded(fn (Request $request) => $request->url() === PlayPushSigner::CERTS_URL)->count();
}

test('a token signed by Google\'s key with every claim in order verifies', function () {
    expect(pushFailure($this->signer->token()))->toBeNull();
});

test('a signature made with another key is refused', function () {
    expect(pushFailure($this->signer->forgedToken()))->toBe('signature');
});

test('a tampered payload is refused', function () {
    [$header, , $signature] = explode('.', $this->signer->token());
    $payload = rtrim(strtr(base64_encode(json_encode([...$this->signer->claims(), 'email' => 'attacker@example.test'])), '+/', '-_'), '=');

    expect(pushFailure("{$header}.{$payload}.{$signature}"))->toBe('signature');
});

test('any algorithm but RS256 is refused, including none and HS256', function (string $alg) {
    expect(pushFailure($this->signer->token(header: ['alg' => $alg])))->toBe('algorithm');
})->with(['none', 'HS256', 'RS384', 'ES256']);

test('a token without a key id is refused', function () {
    expect(pushFailure($this->signer->token(header: ['kid' => null])))->toBe('algorithm');
});

test('a missing, short or garbled bearer is refused', function (?string $bearer) {
    expect(pushFailure($bearer))->toBe('malformed');
})->with([null, '', 'abc', 'a.b', 'a.b.c.d', '!!.!!.!!']);

test('each wrong claim is refused with its own reason', function (array $claims, string $reason) {
    $claims = array_map(fn ($claim) => $claim instanceof Closure ? $claim() : $claim, $claims);

    expect(pushFailure($this->signer->token($claims)))->toBe($reason);
})->with([
    'issuer' => [['iss' => 'https://evil.example'], 'issuer'],
    'audience' => [['aud' => 'https://other.example/hook'], 'audience'],
    'sender' => [['email' => 'attacker@example.test'], 'email'],
    'unverified sender' => [['email_verified' => false], 'email_unverified'],
    'issued six minutes ago' => [['iat' => fn () => time() - 360], 'time'],
    'issued six minutes ahead' => [['iat' => fn () => time() + 360], 'time'],
    'expired' => [['exp' => fn () => time() - 120], 'expired'],
]);

test('the alternative issuer spelling is accepted', function () {
    expect(pushFailure($this->signer->token(['iss' => 'accounts.google.com'])))->toBeNull();
});

test('certificates are fetched from Google once and cached by their cache header', function () {
    pushFailure($this->signer->token());
    pushFailure($this->signer->token());
    pushFailure($this->signer->token());

    expect(certificateFetches())->toBe(1);
});

test('an unknown key id forces exactly one refresh, then is refused; further unknown ids wait a minute', function () {
    $unknown = fn () => $this->signer->token(header: ['kid' => 'rotated-kid']);

    expect(pushFailure($unknown()))->toBe('signature')
        ->and(certificateFetches())->toBe(2) // initial + one refresh
        ->and(pushFailure($unknown()))->toBe('signature')
        ->and(certificateFetches())->toBe(2);
});

test('a rotated key becomes known after the one refresh', function () {
    pushFailure($this->signer->token()); // prime the cache with the old set
    refakeCertificates(Http::response(['rotated-kid' => $this->signer->certificate], 200, ['Cache-Control' => 'max-age=3600']));

    expect(pushFailure($this->signer->token(header: ['kid' => 'rotated-kid'])))->toBeNull();
});

test('certificates are taken only from the configured Google endpoint, never from the token', function () {
    pushFailure($this->signer->token(header: ['jku' => 'https://evil.example/keys', 'x5u' => 'https://evil.example/cert']));

    Http::assertSent(fn (Request $request) => $request->url() === PlayPushSigner::CERTS_URL);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example'));
});

test('with no certificates cached and none obtainable the verifier cannot decide', function () {
    refakeCertificates(Http::response('', 500));

    expect(fn () => pushFailure($this->signer->token()))->toThrow(CertificatesUnavailable::class);
});

test('a certificate set that is not certificates is unavailable, not trusted', function () {
    refakeCertificates(Http::response(['kid' => 'not a certificate'], 200));

    expect(fn () => pushFailure($this->signer->token()))->toThrow(CertificatesUnavailable::class);
});

test('the cache lifetime follows the header, clamped between five minutes and a day', function (string $header, int $seconds) {
    refakeCertificates(Http::response([$this->signer->kid => $this->signer->certificate], 200, ['Cache-Control' => $header]));

    pushFailure($this->signer->token());
    $this->travel($seconds - 10)->seconds();
    pushFailure($this->signer->token());
    expect(certificateFetches())->toBe(1);

    $this->travel(20)->seconds();
    pushFailure($this->signer->token());
    expect(certificateFetches())->toBe(2);
})->with([
    'a short max-age is raised to five minutes' => ['max-age=10', 300],
    'a long max-age is cut to a day' => ['max-age=999999', 86400],
    'a normal one is honoured' => ['public, max-age=7200', 7200],
]);
