<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Identity\Domain\Events\DeletionReminderDue;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An account is three days or less from its purge: the person hears of it
 * (spec 023, FR-027). A pending deletion still receives; the date is only part
 * of the dedupe key, never of the text.
 */
final class NotifyDeletionReminder implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(DeletionReminderDue $event): void
    {
        $this->notifications->create(new NotificationDraft(
            $event->userId,
            MessageType::DeletionReminder,
            'deletion_reminder:'.$event->deletionScheduledFor,
        ));
    }
}
