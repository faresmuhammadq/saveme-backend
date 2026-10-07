<?php

use App\Modules\Billing\Domain\GooglePlay\OidcClaims;

/*
 * The claim checks of a Pub/Sub push token (spec 024, FR-030, R-8; security
 * baseline, Messages from the payment provider). The signature and algorithm
 * checks are exercised through the verifier in
 * tests/Feature/Billing/GooglePlay/PushVerificationTest.php.
 */

const AUD = 'https://saveme.test/api/v1/webhooks/google-play';
const SENDER = 'play-push@example-project.iam.gserviceaccount.com';

function pushClaims(array $overrides = []): array
{
    return array_filter([
        'iss' => 'https://accounts.google.com', 'aud' => AUD, 'email' => SENDER, 'email_verified' => true,
        'iat' => 1_791_000_000, 'exp' => 1_791_003_600, ...$overrides,
    ], fn ($value) => $value !== null);
}

function claimFailure(array $overrides = [], int $now = 1_791_000_030): ?string
{
    return OidcClaims::failure(pushClaims($overrides), AUD, SENDER, (new DateTimeImmutable)->setTimestamp($now));
}

test('a push token with every claim in order passes', function () {
    expect(claimFailure())->toBeNull();
});

test('both Google issuer spellings pass, any other issuer fails', function () {
    expect(claimFailure(['iss' => 'accounts.google.com']))->toBeNull()
        ->and(claimFailure(['iss' => 'https://evil.example']))->toBe('issuer')
        ->and(claimFailure(['iss' => null]))->toBe('issuer');
});

test('the audience must be this service\'s push endpoint, exactly', function () {
    expect(claimFailure(['aud' => 'https://other.example/hook']))->toBe('audience')
        ->and(claimFailure(['aud' => AUD.'/']))->toBe('audience')
        ->and(claimFailure(['aud' => [AUD]]))->toBe('audience')
        ->and(claimFailure(['aud' => null]))->toBe('audience');
});

test('the sender must be the configured service account with a verified address', function () {
    expect(claimFailure(['email' => 'attacker@example.test']))->toBe('email')
        ->and(claimFailure(['email' => null]))->toBe('email')
        ->and(claimFailure(['email_verified' => false]))->toBe('email_unverified')
        ->and(claimFailure(['email_verified' => 'true']))->toBe('email_unverified')
        ->and(claimFailure(['email_verified' => null]))->toBe('email_unverified');
});

test('issued-at must be within five minutes of now, either way', function () {
    expect(claimFailure(['iat' => 1_791_000_030 - 299]))->toBeNull()
        ->and(claimFailure(['iat' => 1_791_000_030 - 301]))->toBe('time')
        ->and(claimFailure(['iat' => 1_791_000_030 + 301]))->toBe('time')
        ->and(claimFailure(['iat' => null]))->toBe('time');
});

test('expiry must be in the future, with 60 seconds of clock leeway', function () {
    expect(claimFailure(['exp' => 1_791_000_030 - 30]))->toBeNull()
        ->and(claimFailure(['exp' => 1_791_000_030 - 61]))->toBe('expired')
        ->and(claimFailure(['exp' => null]))->toBe('expired');
});

test('an unconfigured audience or sender never matches an empty claim', function () {
    expect(OidcClaims::failure(pushClaims(['aud' => '']), '', SENDER, (new DateTimeImmutable)->setTimestamp(1_791_000_030)))->toBe('audience')
        ->and(OidcClaims::failure(pushClaims(['email' => '']), AUD, '', (new DateTimeImmutable)->setTimestamp(1_791_000_030)))->toBe('email');
});
