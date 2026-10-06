<?php

namespace App\Modules\Notifications\Application\Delivery;

use App\Modules\Notifications\Domain\Delivery\NotificationRecord;
use App\Modules\Notifications\Domain\Delivery\SkipReason;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The 5-minute sweep (spec 023, FR-017, FR-018, FR-049, FR-051): releases held
 * notifications whose quiet hours have ended (or no longer apply: preferences
 * are re-read), reduces them to the latest per collapse key, sends the oldest
 * first through the rate limit, and re-queues notifications left `sending` for
 * more than 15 minutes (a worker died). A failure for one notification is
 * logged by id and exception class and does not stop the sweep.
 */
final class ReleaseHeld
{
    private const BATCH = 1000;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly DeliveryDecider $decider,
    ) {}

    /**
     * @return array{released: int, collapsed: int, skipped: int, requeued: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= now()->toDateTimeImmutable();
        $counts = ['released' => 0, 'collapsed' => 0, 'skipped' => 0, 'requeued' => 0];

        $ready = [];

        foreach ($this->notifications->allHeld(self::BATCH) as $held) {
            try {
                $decision = $this->decider->decide($held, $now);

                if ($decision->isSkip() && $decision->reason !== null) {
                    $this->notifications->markSkipped($held->id, $decision->reason);
                    $counts['skipped']++;
                } elseif ($decision->isHold() && $decision->until !== null) {
                    if ($held->notBefore === null || $decision->until->getTimestamp() !== $held->notBefore->getTimestamp()) {
                        $this->notifications->markHeld($held->id, $decision->until);
                    }
                } else {
                    $ready[] = $held;
                }
            } catch (Throwable $failure) {
                $this->logFailure($held, $failure);
            }
        }

        $this->release($ready, $now, $counts);
        $counts['requeued'] = $this->requeueStale($now);

        Log::info('notifications_released', $counts);

        return $counts;
    }

    /**
     * Latest per collapse key and person survives; the survivors go out oldest
     * first, each judged against the rate limit; a released one is marked
     * `sending` at once, which counts toward the limit for the rest of the burst.
     *
     * @param  list<NotificationRecord>  $ready  oldest first
     * @param  array{released: int, collapsed: int, skipped: int, requeued: int}  $counts
     */
    private function release(array $ready, DateTimeImmutable $now, array &$counts): void
    {
        $latest = [];

        foreach ($ready as $notification) {
            $latest[$notification->userId.'|'.$notification->collapseKey] = $notification->id;
        }

        foreach ($ready as $notification) {
            try {
                if ($latest[$notification->userId.'|'.$notification->collapseKey] !== $notification->id) {
                    $this->notifications->markSkipped($notification->id, SkipReason::Collapsed);
                    $counts['collapsed']++;

                    continue;
                }

                $decision = $this->decider->decide($notification, $now);

                if ($decision->isSkip() && $decision->reason !== null) {
                    $this->notifications->markSkipped($notification->id, $decision->reason);
                    $counts['skipped']++;

                    continue;
                }

                // `sending` counts toward the rate limit at once, so the rest of the burst sees it.
                $this->notifications->markSending($notification->id, $now);
                DeliverNotification::dispatch($notification->id)->afterCommit();
                $counts['released']++;
            } catch (Throwable $failure) {
                $this->logFailure($notification, $failure);
            }
        }
    }

    private function requeueStale(DateTimeImmutable $now): int
    {
        $minutes = (int) config('notifications.stale_sending_minutes');
        $requeued = 0;

        foreach ($this->notifications->staleSending($now->modify("-{$minutes} minutes"), self::BATCH) as $stale) {
            try {
                $this->notifications->markSending($stale->id, $now);
                DeliverNotification::dispatch($stale->id)->afterCommit();
                $requeued++;
            } catch (Throwable $failure) {
                $this->logFailure($stale, $failure);
            }
        }

        return $requeued;
    }

    private function logFailure(NotificationRecord $notification, Throwable $failure): void
    {
        Log::warning('notifications_sweep_item_failed', [
            'notification_id' => $notification->id,
            'type' => $notification->type->value,
            'exception' => $failure::class,
        ]);
    }
}
