<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Sharing\Domain\Events\MemberRevoked;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A member was removed by the owner: they hear of it (spec 023, FR-027). A
 * member who leaves is not told what they just did.
 */
final class NotifyMemberRemoved implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(MemberRevoked $event): void
    {
        if ($event->reason !== 'removed') {
            return;
        }

        $this->notifications->create(new NotificationDraft(
            $event->memberUserId,
            MessageType::MemberRemoved,
            'event:'.$event->eventId,
            ['wallet_id' => $event->walletId],
        ));
    }
}
