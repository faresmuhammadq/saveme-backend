<?php

use App\Modules\Admin\Application\Listeners\NotifyOperatorsOfDeletionCancelledBySignIn;
use App\Modules\Admin\Application\Mail\OperatorNotificationMail;
use App\Modules\Admin\Application\OperatorNotificationQueryService;
use App\Modules\Identity\Domain\Events\UserDeletionCancelled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Operators are notified, in the dashboard and by email, when a sign-in
 * cancels a deletion (spec 007, FR-056, R-14). Identifiers only.
 */

beforeEach(function () {
    Mail::fake();
    config(['admin.notifications.recipients' => ['ops@example.test', 'lead@example.test']]);
});

function cancelledEvent(string $reason = 'sign_in', ?string $eventId = null): UserDeletionCancelled
{
    return new UserDeletionCancelled(
        eventId: $eventId ?? (string) Str::uuid7(),
        occurredAt: '2026-09-26T08:30:00.000000Z',
        userId: '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70',
        reason: $reason,
        sessionId: $reason === 'sign_in' ? '0192f7a0-2222-7abc-9f01-2b3c4d5e6f70' : null,
    );
}

function notifyOperators(UserDeletionCancelled $event): void
{
    app(NotifyOperatorsOfDeletionCancelledBySignIn::class)->handle($event);
}

test('a sign-in cancellation stores one notification and emails each recipient', function () {
    notifyOperators(cancelledEvent());

    $row = DB::table('operator_notifications')->sole();
    expect($row->kind)->toBe('deletion_cancelled_by_sign_in')
        ->and($row->subject_user_id)->toBe('0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70')
        ->and(json_decode($row->payload, true))->toBe(['session_id' => '0192f7a0-2222-7abc-9f01-2b3c4d5e6f70']);
    Mail::assertQueued(OperatorNotificationMail::class, 2);
});

test('the same event delivered again adds nothing and sends nothing', function () {
    $event = cancelledEvent();

    notifyOperators($event);
    notifyOperators($event);

    expect(DB::table('operator_notifications')->count())->toBe(1);
    Mail::assertQueued(OperatorNotificationMail::class, 2);
});

test('an explicit cancellation notifies nobody', function () {
    notifyOperators(cancelledEvent('explicit'));

    expect(DB::table('operator_notifications')->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('with no recipients configured the notification is still stored', function () {
    config(['admin.notifications.recipients' => []]);

    notifyOperators(cancelledEvent());

    expect(DB::table('operator_notifications')->count())->toBe(1);
    Mail::assertNothingQueued();
});

test('neither the row nor the mail carries an address or a device name', function () {
    person('ada@example.test');
    signInAs(device: deviceBody(['name' => 'Ada’s Pixel']));

    notifyOperators(cancelledEvent());

    $row = json_encode(DB::table('operator_notifications')->sole());
    $mail = (new OperatorNotificationMail('0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70', '2026-09-26T08:30:00.000000Z'))->render();
    foreach ([$row, $mail] as $text) {
        expect($text)->not->toContain('ada@example.test')->not->toContain('Pixel');
    }
});

test('the query service returns views and counts only the recent ones as unread', function () {
    notifyOperators(cancelledEvent());
    DB::table('operator_notifications')->insert([
        'id' => (string) Str::uuid7(), 'kind' => 'deletion_cancelled_by_sign_in', 'subject_user_id' => (string) Str::uuid7(),
        'occurred_at' => now()->subDays(10), 'payload' => '{}', 'source_event_id' => (string) Str::uuid7(), 'created_at' => now(),
    ]);
    $this->travelTo('2026-09-27 00:00:00');

    $service = app(OperatorNotificationQueryService::class);
    expect($service->latest())->toHaveCount(2)
        ->and($service->latest()[0]->titleKey)->toBe('admin::notifications.kinds.deletion_cancelled_by_sign_in.title')
        ->and($service->unreadCount())->toBe(1);
});
