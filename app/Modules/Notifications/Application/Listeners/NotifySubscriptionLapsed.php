<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A subscription stopped granting fully (into grace, expired or revoked): one
 * notification per event (spec 023, FR-027). The collapse key `subscription`
 * makes a later message replace an earlier one on the phone.
 */
final class NotifySubscriptionLapsed implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SubscriptionLapsed $event): void
    {
        $this->notifications->create(new NotificationDraft(
            $event->userId,
            MessageType::SubscriptionLapsed,
            'event:'.$event->eventId,
        ));
    }
}
