<?php

namespace App\Modules\Admin\Application\Listeners;

use App\Modules\Admin\Application\Mail\OperatorNotificationMail;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;
use App\Modules\Identity\Domain\Events\UserDeletionCancelled;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * When a sign-in cancels an account deletion, operators are told in the
 * dashboard and by email (spec 007, FR-056; owner decision 2026-09-26).
 *
 * Idempotent: the notification is keyed by the event id, and the mail goes
 * out only when this delivery stored it, so a redelivered event does nothing.
 */
final class NotifyOperatorsOfDeletionCancelledBySignIn implements ShouldQueue
{
    public function __construct(private readonly OperatorNotificationStore $store) {}

    public function handle(UserDeletionCancelled $event): void
    {
        if ($event->reason !== 'sign_in') {
            return;
        }

        $stored = $this->store->recordOnce(
            kind: 'deletion_cancelled_by_sign_in',
            subjectUserId: $event->userId,
            occurredAt: new DateTimeImmutable($event->occurredAt),
            payload: ['session_id' => $event->sessionId],
            sourceEventId: $event->eventId,
        );

        if (! $stored) {
            return;
        }

        foreach (config('admin.notifications.recipients') as $recipient) {
            Mail::to($recipient)->queue(new OperatorNotificationMail($event->userId, $event->occurredAt));
        }
    }
}
