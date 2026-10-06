<?php

namespace App\Modules\Notifications\Application\Delivery;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one notification (spec 023, FR-032, FR-033). The payload is the
 * notification id and nothing else: no token, no text, no data. Five tries
 * with backoff 10 s, 60 s, 5 min, 15 min, or the provider's Retry-After when
 * longer. Unique by notification id, so the stale-sending sweep and a late
 * dispatch cannot run it twice at once. The request correlation id travels
 * with the job through the log context (P-15 rule 3).
 */
final class DeliverNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'notifications';

    public int $tries = 5;

    /** A crashed worker must not hold the unique lock for ever. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $notificationId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(NotificationDelivery $delivery): void
    {
        $retryAfter = $delivery->deliver($this->notificationId, $this->attempts(), $this->tries);

        if ($retryAfter !== null) {
            $steps = $this->backoff();
            $step = $steps[min($this->attempts(), count($steps)) - 1];

            $this->release(max($step, $retryAfter));
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(NotificationDelivery::class)->fail($this->notificationId);
    }
}
