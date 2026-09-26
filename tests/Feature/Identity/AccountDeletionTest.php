<?php

use App\Modules\Identity\Application\Deletion\CancelDeletion;
use App\Modules\Identity\Application\Deletion\RequestDeletion;
use App\Modules\Identity\Application\Mail\DeletionScheduledMail;
use App\Modules\Identity\Domain\Events\UserDeletionCancelled;
use App\Modules\Identity\Domain\Events\UserDeletionRequested;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * The deletion request and its grace period (spec 007, FR-050–FR-053,
 * FR-055a; security baseline, Deletion).
 */

beforeEach(function () {
    Mail::fake();
    $this->freezeTime();
    $this->user = person();
});

/**
 * Every row the person owns in Identity, for a before/after comparison.
 *
 * @return array<string, mixed>
 */
function identitySnapshot(string $userId): array
{
    return [
        'user' => DB::table('users')->where('id', $userId)->first(['email_ciphertext', 'email_lookup', 'password_hash', 'email_verified_at']),
        'key' => DB::table('user_keys')->where('user_id', $userId)->first(),
        'profile' => DB::table('profiles')->where('user_id', $userId)->first(),
        'sessions' => DB::table('device_sessions')->where('user_id', $userId)->orderBy('id')->get(['id', 'token', 'state', 'version'])->all(),
    ];
}

test('a request schedules the purge exactly 30 days out, audits and announces it', function () {
    Event::fake([UserDeletionRequested::class]);

    $scheduledFor = app(RequestDeletion::class)->handle($this->user->id, 'correct horse');

    $user = User::query()->find($this->user->id);
    expect($user->state)->toBe('pending_deletion')
        ->and($user->deletion_scheduled_for->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($scheduledFor->getTimestamp())->toBe(now()->addDays(30)->getTimestamp())
        ->and(AuditEntry::query()->where('event', 'deletion_requested')->count())->toBe(1);
    Event::assertDispatchedTimes(UserDeletionRequested::class, 1);
    Mail::assertQueued(DeletionScheduledMail::class, fn (DeletionScheduledMail $mail): bool => $mail->hasTo('ada@example.test'));
});

test('asking again five days later keeps the date and sends nothing more', function () {
    $first = app(RequestDeletion::class)->handle($this->user->id, 'correct horse');
    $this->travel(5)->days();

    $second = app(RequestDeletion::class)->handle($this->user->id, 'correct horse');

    expect($second->getTimestamp())->toBe($first->getTimestamp());
    Mail::assertQueued(DeletionScheduledMail::class, 1);
});

test('cancelling loses nothing', function () {
    signInAs();
    $before = identitySnapshot($this->user->id);
    app(RequestDeletion::class)->handle($this->user->id, 'correct horse');
    Event::fake([UserDeletionCancelled::class]);

    expect(app(CancelDeletion::class)->cancel($this->user->id, 'explicit'))->toBeTrue();

    expect(User::query()->find($this->user->id)->state)->toBe('active')
        ->and(identitySnapshot($this->user->id))->toEqual($before)
        ->and(AuditEntry::query()->where('event', 'deletion_cancelled')->sole()->detail)->toBe(['reason' => 'explicit']);
    Event::assertDispatched(UserDeletionCancelled::class, fn (UserDeletionCancelled $event): bool => $event->reason === 'explicit');
});

test('existing sessions keep working through the grace period, writes included', function () {
    $credential = signInAs()->json('data.credential');
    app(RequestDeletion::class)->handle($this->user->id, 'correct horse');

    $this->withHeaders(asCredential($credential))->get('/api/v1/auth/sessions')->assertOk();
    $this->flushHeaders();
    $this->withHeaders(asCredential($credential))->deleteJson('/api/v1/auth/sessions')->assertNoContent();

    expect(User::query()->find($this->user->id)->state)->toBe('pending_deletion');
});
