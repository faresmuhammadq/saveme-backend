<?php

namespace App\Modules\Admin\Application\Listeners;

use App\Modules\Admin\Application\Mail\OperatorNotificationMail;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;
use App\Modules\Identity\Domain\Events\AccountPurgeStalled;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * When an account purge has run for more than 24 hours without finishing,
 * operators are told in the dashboard and by email (spec 022, FR-063): the
 * user id and the instant, nothing else.
 *
 * Idempotent: the notification is keyed by the event id, and the mail goes
 * out only when this delivery stored it, so a redelivered event does nothing.
 */
final class NotifyOperatorsOfStalledPurge implements ShouldQueue
{
    public function __construct(private readonly OperatorNotificationStore $store) {}

    public function handle(AccountPurgeStalled $event): void
    {
        $stored = $this->store->recordOnce(
            kind: 'account_purge_stalled',
            subjectUserId: $event->userId,
            occurredAt: new DateTimeImmutable($event->occurredAt),
            payload: ['started_at' => $event->startedAt],
            sourceEventId: $event->eventId,
        );

        if (! $stored) {
            return;
        }

        foreach (config('admin.notifications.recipients') as $recipient) {
            Mail::to($recipient)->queue(new OperatorNotificationMail($event->userId, $event->occurredAt, 'account_purge_stalled'));
        }
    }
}
