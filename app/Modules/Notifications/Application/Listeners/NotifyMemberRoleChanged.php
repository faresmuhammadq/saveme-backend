<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Sharing\Domain\Events\MemberRoleChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A member's role changed, by the owner or by a plan change: the member hears
 * of it, with the wallet id and the new role (spec 023, FR-027). A change the
 * member made themselves is not pushed.
 */
final class NotifyMemberRoleChanged implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(MemberRoleChanged $event): void
    {
        if ($event->actorUserId === $event->memberUserId) {
            return;
        }

        $this->notifications->create(new NotificationDraft(
            $event->memberUserId,
            MessageType::MemberRoleChanged,
            'event:'.$event->eventId,
            ['wallet_id' => $event->walletId, 'role' => $event->role],
        ));
    }
}
