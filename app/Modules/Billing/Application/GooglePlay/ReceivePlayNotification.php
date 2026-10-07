<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Application\GooglePlay\Jobs\ProcessPlayNotification;
use App\Modules\Billing\Domain\GooglePlay\DeveloperNotification;
use App\Modules\Billing\Domain\Ports\PlayNotificationRepository;
use App\Modules\Billing\Domain\Ports\PlayTokenSealer;
use App\Modules\Billing\Domain\Ports\TokenLookup;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Throwable;

/**
 * A verified Pub/Sub push becomes an inbox row (spec 024, R-7, FR-032 to
 * FR-035). Runs only after the push's OIDC token verified. A repeat message
 * id changes nothing; a push for another subscription or package, a test
 * notification, and one that cannot be read are stored as `ignored` so Pub/Sub
 * stops retrying them; everything else is stored `received` with its token
 * sealed and processed by a queued job. The raw body is never kept.
 */
final class ReceivePlayNotification
{
    public function __construct(
        private readonly PlayNotificationRepository $notifications,
        private readonly PlayTokenSealer $sealer,
        private readonly TokenLookup $lookup,
    ) {}

    /**
     * @param  array{message: array{data: string, messageId: string, publishTime?: string}, subscription: string}  $push
     */
    public function receive(array $push): void
    {
        $decoded = base64_decode($push['message']['data'], true);
        $notification = $decoded === false ? null : DeveloperNotification::parse($decoded);

        $id = (string) Str::uuid7();
        $reason = match (true) {
            $notification === null => 'malformed',
            ! hash_equals((string) config('billing.google_play.push.subscription'), $push['subscription']) => 'foreign_subscription',
            ! hash_equals((string) config('billing.google_play.package_name'), $notification->packageName) => 'foreign_package',
            $notification->kind === DeveloperNotification::TEST => 'test',
            default => null,
        };

        $token = $reason === null ? $notification->purchaseToken : null;

        $inserted = $this->notifications->insertIfAbsent([
            'id' => $id,
            'message_id' => $push['message']['messageId'],
            'kind' => $notification === null ? 'malformed' : $notification->kind,
            'notification_type' => $notification?->notificationType,
            'product_id' => $notification?->productId,
            'token_lookup' => $token === null ? null : $this->lookup->for($token),
            'token_sealed' => $token === null ? null : $this->sealer->seal($token, 'play_notification', $id),
            'event_at' => $notification?->eventTimeMillis === null ? null : (new DateTimeImmutable('@'.intdiv($notification->eventTimeMillis, 1000)))->setTimezone(new DateTimeZone('UTC')),
            'published_at' => self::instant($push['message']['publishTime'] ?? null),
            'received_at' => now()->toDateTimeImmutable(),
            'state' => $reason === null ? 'received' : 'ignored',
            'ignored_reason' => $reason,
        ]);

        if ($inserted && $reason === null) {
            ProcessPlayNotification::dispatch($id)->afterCommit();
        }
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
