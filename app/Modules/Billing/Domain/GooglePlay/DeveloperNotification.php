<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * A parsed real-time developer notification (spec 024, R-7): which purchase
 * to read, never what to believe. Malformed input parses to null.
 */
final readonly class DeveloperNotification
{
    public const SUBSCRIPTION = 'subscription';

    public const ONE_TIME = 'one_time';

    public const VOIDED = 'voided';

    public const TEST = 'test';

    private function __construct(
        public string $packageName,
        public ?int $eventTimeMillis,
        public string $kind,
        public ?int $notificationType,
        #[\SensitiveParameter] public ?string $purchaseToken,
        public ?string $productId,
    ) {}

    public static function parse(string $json): ?self
    {
        $body = json_decode($json, true);

        if (! is_array($body) || ! isset($body['packageName']) || ! is_string($body['packageName']) || $body['packageName'] === '') {
            return null;
        }

        $package = $body['packageName'];
        $event = isset($body['eventTimeMillis']) && is_numeric($body['eventTimeMillis']) ? (int) $body['eventTimeMillis'] : null;

        if (isset($body['testNotification']) && is_array($body['testNotification'])) {
            return new self($package, $event, self::TEST, null, null, null);
        }

        foreach ([
            'subscriptionNotification' => [self::SUBSCRIPTION, 'subscriptionId'],
            'oneTimeProductNotification' => [self::ONE_TIME, 'sku'],
        ] as $member => [$kind, $productMember]) {
            $part = $body[$member] ?? null;

            if (is_array($part)) {
                $token = $part['purchaseToken'] ?? null;
                $type = $part['notificationType'] ?? null;

                if (! is_string($token) || $token === '' || ! is_int($type)) {
                    return null;
                }

                $product = $part[$productMember] ?? null;

                return new self($package, $event, $kind, $type, $token, is_string($product) && $product !== '' ? $product : null);
            }
        }

        $voided = $body['voidedPurchaseNotification'] ?? null;

        if (is_array($voided)) {
            $token = $voided['purchaseToken'] ?? null;

            if (! is_string($token) || $token === '') {
                return null;
            }

            $refund = $voided['refundType'] ?? null;

            return new self($package, $event, self::VOIDED, is_int($refund) ? $refund : null, $token, null);
        }

        return null;
    }
}
