<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Identity\Domain\Events\ExportReady;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An export is ready: the requester hears of it, with the export id and never
 * a link (spec 023, FR-027); the signed link stays in the mail.
 */
final class NotifyExportReady implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(ExportReady $event): void
    {
        $this->notifications->create(new NotificationDraft(
            $event->userId,
            MessageType::ExportReady,
            'export:'.$event->exportId,
            ['export_id' => $event->exportId],
        ));
    }
}
