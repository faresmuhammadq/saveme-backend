<?php

use App\Modules\Admin\Application\Mail\OperatorNotificationMail;
use App\Modules\Identity\Application\Deletion\RequestDeletion;
use App\Modules\Identity\Application\Mail\DeletionCancelledBySignInMail;
use App\Modules\Identity\Domain\Events\UserDeletionCancelled;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * A new sign-in during the grace period cancels the deletion; the person is
 * told by mail and operators are notified (spec 007, FR-054–FR-056;
 * owner decision 2026-09-26).
 */

beforeEach(function () {
    Mail::fake();
    config(['admin.notifications.recipients' => ['ops@example.test']]);
    $this->user = person();
    $this->existing = signInAs()->json('data.credential');
    app(RequestDeletion::class)->handle($this->user->id, 'correct horse');
});

test('a new sign-in cancels, says so, audits once, and mails the person', function () {
    $response = signInAs(device: deviceBody(['name' => 'Galaxy S24']))->assertOk();

    expect($response->json('data.deletion_cancelled'))->toBeTrue()
        ->and($response->json('data.account.state'))->toBe('active')
        ->and(User::query()->find($this->user->id)->state)->toBe('active')
        ->and(AuditEntry::query()->where('event', 'deletion_cancelled')->sole()->detail)->toBe(['reason' => 'sign_in']);
    Mail::assertQueued(DeletionCancelledBySignInMail::class, fn (DeletionCancelledBySignInMail $mail): bool => $mail->hasTo('ada@example.test') && $mail->deviceName === 'Galaxy S24');
});

test('operators get one dashboard notification and one email per recipient', function () {
    $session = signInAs()->json('data.session.id');

    $row = DB::table('operator_notifications')->sole();
    expect($row->kind)->toBe('deletion_cancelled_by_sign_in')
        ->and($row->subject_user_id)->toBe($this->user->id)
        ->and(json_decode($row->payload, true))->toBe(['session_id' => $session]);
    Mail::assertQueued(OperatorNotificationMail::class, 1);
    Mail::assertQueued(OperatorNotificationMail::class, fn (OperatorNotificationMail $mail): bool => $mail->hasTo('ops@example.test'));
});

test('the event is published once with the reason and the session', function () {
    Event::fake([UserDeletionCancelled::class]);
    $session = signInAs()->json('data.session.id');

    Event::assertDispatchedTimes(UserDeletionCancelled::class, 1);
    Event::assertDispatched(UserDeletionCancelled::class, fn (UserDeletionCancelled $event): bool => $event->reason === 'sign_in' && $event->sessionId === $session);
});

test('a retried sign-in cancels nothing more and sends nothing more', function () {
    $device = deviceBody();
    signInAs(device: $device);
    $retry = signInAs(device: $device)->assertOk();

    expect($retry->json('data.deletion_cancelled'))->toBeFalse();
    Mail::assertQueued(DeletionCancelledBySignInMail::class, 1);
    expect(DB::table('operator_notifications')->count())->toBe(1);
});

test('a failed or locked sign-in cancels nothing', function () {
    foreach (range(1, 6) as $attempt) {
        signInAs(password: 'not the password');
    }
    signInAs()->assertStatus(423);

    expect(User::query()->find($this->user->id)->state)->toBe('pending_deletion');
    Mail::assertNotQueued(DeletionCancelledBySignInMail::class);
});

test('using an existing session cancels nothing', function () {
    $this->withHeaders(asCredential($this->existing))->get('/api/v1/auth/sessions')->assertOk();

    expect(User::query()->find($this->user->id)->state)->toBe('pending_deletion');
});

test('the cancellation and the new session commit together', function () {
    DB::statement("CREATE OR REPLACE FUNCTION fail_audit() RETURNS trigger AS \$\$ BEGIN IF NEW.event = 'deletion_cancelled' THEN RAISE EXCEPTION 'boom'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
    DB::statement('CREATE TRIGGER fail_audit BEFORE INSERT ON audit_entries FOR EACH ROW EXECUTE FUNCTION fail_audit()');
    $device = deviceBody();

    signInAs(device: $device)->assertStatus(500);

    expect(User::query()->find($this->user->id)->state)->toBe('pending_deletion')
        ->and(DB::table('device_sessions')->where('id', $device['session_id'])->exists())->toBeFalse();
});
