<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use RuntimeException;

/**
 * Chooses the Play gateway by `billing.google_play.driver` and refuses, at
 * boot, the ones that must not run (spec 024, FR-058): `fake` outside local
 * and testing; `google` without a readable service-account key, a package
 * name, a push audience, a push service account and subscription, an
 * account-reference key and a lookup key. `disabled` is allowed everywhere.
 */
final class PlayGatewayFactory
{
    /**
     * @throws RuntimeException
     */
    public static function assertBootable(string $driver, string $environment): void
    {
        match ($driver) {
            'disabled' => null,
            'fake' => in_array($environment, ['local', 'testing'], true)
                ? null
                : throw new RuntimeException('The "fake" Google Play gateway is refused outside local and testing: it verifies nothing.'),
            'google' => self::assertGoogleConfigured(),
            default => throw new RuntimeException("Unknown Google Play driver [{$driver}]: use google, fake or disabled."),
        };
    }

    /**
     * @throws RuntimeException
     */
    public static function make(string $driver): PlayBillingGateway
    {
        return match ($driver) {
            'disabled' => new DisabledPlayBillingGateway,
            'fake' => new FakePlayBillingGateway,
            'google' => new GooglePlayGateway(
                new ServiceAccountTokenSource(ServiceAccountKey::fromConfig()),
                (string) config('billing.google_play.package_name'),
            ),
            default => throw new RuntimeException("Unknown Google Play driver [{$driver}]: use google, fake or disabled."),
        };
    }

    private static function assertGoogleConfigured(): void
    {
        $required = [
            'BILLING_PLAY_PACKAGE' => 'package_name',
            'BILLING_PLAY_PUSH_AUDIENCE' => 'push.audience',
            'BILLING_PLAY_PUSH_SERVICE_ACCOUNT' => 'push.service_account',
            'BILLING_PLAY_PUSH_SUBSCRIPTION' => 'push.subscription',
            'BILLING_PLAY_LOOKUP_KEY' => 'lookup_key',
            'BILLING_PLAY_ACCOUNT_ACTIVE_KEY' => 'account_reference_active_key',
        ];

        foreach ($required as $variable => $key) {
            $value = config("billing.google_play.{$key}");

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("The \"google\" Google Play gateway needs {$variable}.");
            }
        }

        if (config('billing.google_play.account_reference_keys') === []) {
            throw new RuntimeException('The "google" Google Play gateway needs BILLING_PLAY_ACCOUNT_KEYS.');
        }

        ServiceAccountKey::fromConfig();
    }
}
