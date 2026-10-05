<?php

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Deletion\CancelDeletion;
use App\Modules\Identity\Application\Deletion\RequestDeletion;
use App\Modules\Identity\Application\Deletion\SendDeletionReminders;
use App\Modules\Identity\Application\Jobs\SendDeletionReminderMail;
use App\Modules\Identity\Application\Mail\DeletionReminderMail;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * One reminder, three days before the purge (spec 022, FR-058).
 */

beforeEach(function () {
    Mail::fake();
    $this->freezeTime();
    $this->user = namedPerson('ada@example.test', null);
    app(RequestDeletion::class)->handle($this->user->id, 'correct horse');
});

function remindersSent(): int
{
    return count(Mail::queued(DeletionReminderMail::class)) + count(Mail::sent(DeletionReminderMail::class));
}

test('nothing is sent until the purge is three days away, then one reminder in the profile language', function () {
    $this->travel(26)->days();
    app(SendDeletionReminders::class)->run();
    expect(remindersSent())->toBe(0);

    $this->travel(1)->days();
    expect(app(SendDeletionReminders::class)->run())->toBe(1);

    Mail::assertSent(DeletionReminderMail::class, fn (DeletionReminderMail $mail): bool => $mail->hasTo('ada@example.test') && $mail->locale === 'en' && str_contains($mail->render(), '2026-') && str_contains($mail->render(), 'Google Play'));
});

test('a second run sends nothing more', function () {
    $this->travel(28)->days();

    app(SendDeletionReminders::class)->run();
    app(SendDeletionReminders::class)->run();
    $this->travel(1)->days();
    app(SendDeletionReminders::class)->run();

    expect(remindersSent())->toBe(1)
        ->and(DB::table('users')->where('id', $this->user->id)->value('deletion_reminded_at'))->not->toBeNull();
});

test('the reminder is in Arabic for an Arabic profile', function () {
    $arabic = namedPerson('arabic@example.test', null, 'ar');
    app(RequestDeletion::class)->handle($arabic->id, 'correct horse');
    $this->travel(28)->days();

    app(SendDeletionReminders::class)->run();

    Mail::assertSent(DeletionReminderMail::class, fn (DeletionReminderMail $mail): bool => $mail->hasTo('arabic@example.test') && $mail->locale === 'ar');
});

test('cancelling and asking again resets the reminder, so a new one comes later', function () {
    $this->travel(28)->days();
    app(SendDeletionReminders::class)->run();
    expect(remindersSent())->toBe(1);

    app(CancelDeletion::class)->cancel($this->user->id, 'explicit');
    expect(DB::table('users')->where('id', $this->user->id)->value('deletion_reminded_at'))->toBeNull();

    app(RequestDeletion::class)->handle($this->user->id, 'correct horse');
    app(SendDeletionReminders::class)->run();
    expect(remindersSent())->toBe(1);

    $this->travel(28)->days();
    app(SendDeletionReminders::class)->run();
    expect(remindersSent())->toBe(2);
});

test('an account that is active, or already purging, is not reminded', function () {
    $active = person('active@example.test');
    $purging = person('purging@example.test');
    DB::table('users')->where('id', $purging->id)->update(['state' => 'purging', 'deletion_requested_at' => now()->subDays(29), 'deletion_scheduled_for' => now()->subDay()]);
    app(CancelDeletion::class)->cancel($this->user->id, 'explicit');

    app(SendDeletionReminders::class)->run();

    expect(remindersSent())->toBe(0);
});

test('a deletion cancelled after the reminder was queued sends nothing, and the job is encrypted', function () {
    Queue::fake();
    $this->travel(28)->days();
    app(SendDeletionReminders::class)->run();
    app(CancelDeletion::class)->cancel($this->user->id, 'explicit');

    (new SendDeletionReminderMail($this->user->id))->handle(app(UserRepository::class), app(Accounts::class), app(ProfileRepository::class));

    expect(remindersSent())->toBe(0)
        ->and(new SendDeletionReminderMail('x'))->toBeInstanceOf(ShouldBeEncrypted::class);
    Queue::assertPushed(SendDeletionReminderMail::class, 1);
});

test('the command and its daily schedule exist', function () {
    $this->artisan('identity:send-deletion-reminders')->assertSuccessful();

    expect(collect(app(Schedule::class)->events())->contains(fn ($event): bool => str_contains($event->command, 'identity:send-deletion-reminders')))->toBeTrue();
});
