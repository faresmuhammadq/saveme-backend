<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Sharing\Application\InvitationQueryService;
use App\Modules\Sharing\Domain\Events\InvitationSent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An email invitation was sent: a registered, verified addressee is told to
 * check their email (spec 023, FR-025). A link invitation, an unknown or
 * unverified address and the inviter themselves get nothing. The push carries
 * the invitation id only, never the token.
 */
final class NotifyInvitationReceived implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(
        private readonly InvitationQueryService $invitations,
        private readonly NotificationService $notifications,
    ) {}

    public function shouldQueue(InvitationSent $event): bool
    {
        return $event->channel === 'email';
    }

    public function handle(InvitationSent $event): void
    {
        if ($event->channel !== 'email') {
            return;
        }

        $addressee = $this->invitations->verifiedAddresseeOf($event->invitationId);

        if ($addressee === null) {
            return;
        }

        $this->notifications->create(new NotificationDraft(
            $addressee,
            MessageType::InvitationReceived,
            'invitation:'.$event->invitationId,
            ['invitation_id' => $event->invitationId],
        ));
    }
}
