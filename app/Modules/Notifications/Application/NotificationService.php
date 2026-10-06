<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Application\Delivery\DeliverNotification;
use App\Modules\Notifications\Application\Delivery\DeliveryDecider;
use App\Modules\Notifications\Domain\Delivery\NotificationRecord;
use App\Modules\Notifications\Domain\Delivery\SkipReason;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;

/**
 * The one door through which a source asks for a push (spec 023, FR-024).
 * Every message is a Push Notification row under a per-person dedupe key: a
 * key that exists does nothing, so a redelivered event or a rerun pass never
 * creates a second one. A new row is judged at once by the delivery policy
 * (skipped, held until quiet hours end, or queued for delivery); the job
 * judges again when it runs.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly DeliveryDecider $decider,
    ) {}

    /**
     * @return string|null the notification id, or null when the key existed
     */
    public function create(NotificationDraft $draft): ?string
    {
        $notification = $this->notifications->createIfAbsent($draft);

        if ($notification === null) {
            return null;
        }

        $this->route($notification);

        return $notification->id;
    }

    /**
     * Records a reminder folded into a summary: it is "reminded" for good, and
     * nothing is sent for it (spec 023, FR-023).
     *
     * @return bool whether a marker was recorded
     */
    public function recordSummarised(NotificationDraft $draft): bool
    {
        return $this->notifications->createIfAbsent($draft, SkipReason::Summarised) !== null;
    }

    private function route(NotificationRecord $notification): void
    {
        $decision = $this->decider->decide($notification, now()->toDateTimeImmutable());

        if ($decision->isSkip() && $decision->reason !== null) {
            $this->notifications->markSkipped($notification->id, $decision->reason);

            return;
        }

        if ($decision->isHold() && $decision->until !== null) {
            $this->notifications->markHeld($notification->id, $decision->until);

            return;
        }

        DeliverNotification::dispatch($notification->id)->afterCommit();
    }
}
