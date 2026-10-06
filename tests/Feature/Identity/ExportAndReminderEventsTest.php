<?php

use App\Modules\Identity\Application\Deletion\RequestDeletion;
use App\Modules\Identity\Application\Deletion\SendDeletionReminders;
use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Application\Jobs\SendDeletionReminderMail;
use App\Modules\Identity\Application\Jobs\SendExportReadyMail;
use App\Modules\Identity\Domain\Events\DeletionReminderDue;
use App\Modules\Identity\Domain\Events\ExportReady;
use App\Modules\Identity\Domain\Export\ExportFailure;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Ports\ExportStore;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Export ready and the deletion reminder also publish events (spec 023,
 * FR-027), beside their mails, which are unchanged.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->freezeTime();
    $this->user = namedPerson('ada@example.test', null);
    app()->bind(GenerateExport::class, fn () => new GenerateExport(app(ExportRepository::class), app(ExportStore::class), app(UserRepository::class), []));
});

test('an export reaching ready publishes ExportReady once, and the mail is still queued', function () {
    Queue::fake();
    Event::fake([ExportReady::class]);
    $export = DataExport::factory()->create(['user_id' => $this->user->id]);

    app(GenerateExport::class)->run($export->id);
    app(GenerateExport::class)->run($export->id);

    Event::assertDispatchedTimes(ExportReady::class, 1);
    Event::assertDispatched(ExportReady::class, fn (ExportReady $event): bool => $event->userId === $this->user->id && $event->exportId === $export->id && ! empty($event->eventId));
    Queue::assertPushed(SendExportReadyMail::class, 1);
});

test('a failed export publishes nothing', function () {
    Queue::fake();
    Event::fake([ExportReady::class]);
    $export = DataExport::factory()->create(['user_id' => $this->user->id]);

    app(GenerateExport::class)->fail($export->id, ExportFailure::GenerationFailed);

    Event::assertNotDispatched(ExportReady::class);
});

test('the deletion reminder publishes DeletionReminderDue with the scheduled instant, and the mail is unchanged', function () {
    Queue::fake();
    Event::fake([DeletionReminderDue::class]);
    app(RequestDeletion::class)->handle($this->user->id, 'correct horse');
    $scheduled = app(UserRepository::class)->find($this->user->id)->deletionScheduledFor;
    $this->travel(28)->days();

    app(SendDeletionReminders::class)->run();
    app(SendDeletionReminders::class)->run();

    Event::assertDispatchedTimes(DeletionReminderDue::class, 1);
    Event::assertDispatched(DeletionReminderDue::class, fn (DeletionReminderDue $event): bool => $event->userId === $this->user->id
        && $event->deletionScheduledFor === $scheduled->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
    Queue::assertPushed(SendDeletionReminderMail::class, 1);
});

test('no event for a person who was not reminded', function () {
    Queue::fake();
    Event::fake([DeletionReminderDue::class]);
    app(RequestDeletion::class)->handle($this->user->id, 'correct horse');

    app(SendDeletionReminders::class)->run();

    Event::assertNotDispatched(DeletionReminderDue::class);
});
