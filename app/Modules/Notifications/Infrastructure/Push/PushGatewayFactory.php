<?php

namespace App\Modules\Notifications\Infrastructure\Push;

use App\Modules\Notifications\Domain\Delivery\PushGateway;
use RuntimeException;

/**
 * Chooses the gateway by `notifications.push.driver` and refuses, at boot,
 * the ones that must not run (spec 023, FR-041): `log` outside local and
 * testing, `fcm` without a project id and a readable service-account key.
 */
final class PushGatewayFactory
{
    /**
     * @param  string  $environment  the current environment name
     *
     * @throws RuntimeException
     */
    public static function assertBootable(string $driver, string $environment): void
    {
        match ($driver) {
            'disabled' => null,
            'log' => in_array($environment, ['local', 'testing'], true)
                ? null
                : throw new RuntimeException('The "log" push gateway is refused outside local and testing: it delivers nothing.'),
            'fcm' => self::assertFcmConfigured(),
            default => throw new RuntimeException("Unknown push driver [{$driver}]: use fcm, log or disabled."),
        };
    }

    /**
     * @throws RuntimeException
     */
    public static function make(string $driver): PushGateway
    {
        return match ($driver) {
            'disabled' => new DisabledPushGateway,
            'log' => new LogPushGateway,
            'fcm' => new FcmPushGateway(
                new ServiceAccountTokenSource(ServiceAccountKey::fromConfig()),
                (string) config('notifications.push.fcm.project_id'),
            ),
            default => throw new RuntimeException("Unknown push driver [{$driver}]: use fcm, log or disabled."),
        };
    }

    private static function assertFcmConfigured(): void
    {
        $project = config('notifications.push.fcm.project_id');

        if (! is_string($project) || trim($project) === '') {
            throw new RuntimeException('The "fcm" push gateway needs NOTIFICATIONS_FCM_PROJECT_ID.');
        }

        ServiceAccountKey::fromConfig();
    }
}
