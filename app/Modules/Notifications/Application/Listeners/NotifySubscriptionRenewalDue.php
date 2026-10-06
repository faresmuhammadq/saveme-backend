<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Billing\Domain\Events\SubscriptionRenewalDue;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An InstaPay subscription ends within the reminder window: the second, shorter
 * path beside the renewal mail (spec 023, FR-027).
 */
final class NotifySubscriptionRenewalDue implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SubscriptionRenewalDue $event): void
    {
        $this->notifications->create(new NotificationDraft(
            $event->userId,
            MessageType::SubscriptionEnding,
            'renewal:'.$event->subscriptionId,
        ));
    }
}
