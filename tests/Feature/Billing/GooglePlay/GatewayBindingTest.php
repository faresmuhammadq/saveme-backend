<?php

use App\Modules\Billing\Domain\GooglePlay\GatewayOutcome;
use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Infrastructure\BillingPersistenceServiceProvider;
use App\Modules\Billing\Infrastructure\GooglePlay\DisabledPlayBillingGateway;
use App\Modules\Billing\Infrastructure\GooglePlay\FakePlayBillingGateway;
use App\Modules\Billing\Infrastructure\GooglePlay\GooglePlayGateway;
use App\Modules\Billing\Infrastructure\GooglePlay\PlayGatewayFactory;
use App\Modules\Billing\Infrastructure\GooglePlay\ServiceAccountKey;

/*
 * The adapter is chosen by configuration and the unsafe ones refuse to boot
 * (spec 024, FR-057, FR-058; ADR-0026 rule 2).
 */

function googleConfigured(array $overrides = []): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    config(['billing.google_play' => [
        ...config('billing.google_play'),
        'driver' => 'google',
        'credentials' => base64_encode(json_encode(['client_email' => 'svc@example.test', 'private_key_id' => 'k', 'private_key' => $pem])),
        'package_name' => 'com.example.app',
        ...$overrides,
    ]]);
}

test('the suite runs on the scripted fake', function () {
    expect(app(PlayBillingGateway::class))->toBeInstanceOf(FakePlayBillingGateway::class);
});

test('unset means disabled: it reads nothing and acknowledges nothing', function () {
    config(['billing.google_play.driver' => 'disabled']);
    app()->forgetInstance(PlayBillingGateway::class);
    $gateway = app(PlayBillingGateway::class);

    expect($gateway)->toBeInstanceOf(DisabledPlayBillingGateway::class)
        ->and($gateway->readSubscription('t')->kind)->toBe(GatewayOutcome::UNAVAILABLE)
        ->and($gateway->readProduct('p', 't')->kind)->toBe(GatewayOutcome::UNAVAILABLE)
        ->and($gateway->acknowledge(PlayProductKind::Subscription, 'p', 't')->kind)->toBe(GatewayOutcome::UNAVAILABLE)
        ->and($gateway->voidedSince(new DateTimeImmutable, null)->available)->toBeFalse();
});

test('the shipped default is disabled', function () {
    $config = require base_path('config/billing.php');

    expect(array_key_exists('driver', $config['google_play']))->toBeTrue();
    expect(file_get_contents(base_path('config/billing.php')))->toContain("env('BILLING_PLAY_DRIVER', 'disabled')");
});

test('fake is refused outside local and testing', function (string $environment) {
    expect(fn () => PlayGatewayFactory::assertBootable('fake', $environment))->toThrow(RuntimeException::class, 'fake');
})->with(['production', 'staging']);

test('fake boots in local and testing, disabled everywhere', function () {
    PlayGatewayFactory::assertBootable('fake', 'local');
    PlayGatewayFactory::assertBootable('fake', 'testing');
    PlayGatewayFactory::assertBootable('disabled', 'production');

    expect(true)->toBeTrue();
});

test('an unknown driver is refused', function () {
    expect(fn () => PlayGatewayFactory::assertBootable('sandbox', 'local'))->toThrow(RuntimeException::class, 'Unknown');
    expect(fn () => PlayGatewayFactory::make('sandbox'))->toThrow(RuntimeException::class);
});

test('google boots only with a key, a package, a push audience, sender and subscription, and both keys', function (string $key, mixed $value) {
    googleConfigured([$key => $value]);

    expect(fn () => PlayGatewayFactory::assertBootable('google', 'production'))->toThrow(RuntimeException::class);
})->with([
    'no credentials' => ['credentials', null],
    'unreadable credentials' => ['credentials', 'not-a-key'],
    'no package' => ['package_name', ''],
    'no audience' => ['push', ['audience' => '', 'service_account' => 'a@b.c', 'subscription' => 's', 'certs_url' => 'u']],
    'no sender' => ['push', ['audience' => 'a', 'service_account' => '', 'subscription' => 's', 'certs_url' => 'u']],
    'no subscription' => ['push', ['audience' => 'a', 'service_account' => 'a@b.c', 'subscription' => '', 'certs_url' => 'u']],
    'no lookup key' => ['lookup_key', null],
    'no account-reference keys' => ['account_reference_keys', []],
    'no active account-reference key' => ['account_reference_active_key', null],
]);

test('a fully configured google driver boots and builds the real adapter', function () {
    googleConfigured();

    PlayGatewayFactory::assertBootable('google', 'production');

    expect(PlayGatewayFactory::make('google'))->toBeInstanceOf(GooglePlayGateway::class);
});

test('the application itself refuses to boot with fake in production', function () {
    $this->app['env'] = 'production';
    config(['billing.google_play.driver' => 'fake']);

    expect(fn () => (new BillingPersistenceServiceProvider($this->app))->boot())->toThrow(RuntimeException::class);
});

test('the service-account key never appears in a dump', function () {
    googleConfigured();
    $key = ServiceAccountKey::fromConfig();

    expect(print_r($key, true))->not->toContain('PRIVATE KEY');
});
