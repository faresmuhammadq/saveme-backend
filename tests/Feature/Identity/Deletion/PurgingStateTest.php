<?php

use App\Modules\Identity\Application\Deletion\CancelDeletion;
use App\Modules\Identity\Application\Deletion\PurgeAccount;
use App\Modules\Identity\Application\Jobs\PurgeAccountJob;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * The `purging` state (spec 022, FR-051, FR-052): sessions end at once, nobody
 * signs in, nobody cancels, and only a due account starts.
 */

beforeEach(function () {
    Mail::fake();
    $this->user = person();
    $this->first = signInAs()->json('data.credential');
    $this->second = signInAs('ada@example.test', 'correct horse', deviceBody(['device_id' => 'second-phone']))->json('data.credential');
    $this->third = signInAs('ada@example.test', 'correct horse', deviceBody(['device_id' => 'third-phone']))->json('data.credential');
});

/**
 * Leaves the account `purging`: the purge crashes right after its first step.
 */
function leaveAccountPurging(User $user): void
{
    dueForPurge($user);
    crashPurgeOnceAfter(PurgeStep::EraseName);

    try {
        app(PurgeAccount::class)->run($user->id);
    } catch (RuntimeException) {
    }
}

test('a due pending account becomes purging and every session ends as account_deleted, one session_revoked each, by the system', function () {
    leaveAccountPurging($this->user);

    expect(User::query()->find($this->user->id)->state)->toBe('purging');

    $sessions = DeviceSession::query()->where('user_id', $this->user->id)->get();
    expect($sessions)->toHaveCount(3)
        ->and($sessions->pluck('state')->unique()->all())->toBe(['revoked'])
        ->and($sessions->pluck('end_reason')->unique()->all())->toBe(['account_deleted']);

    $entries = AuditEntry::query()->where('event', 'session_revoked')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('actor_type')->unique()->all())->toBe(['system'])
        ->and($entries->pluck('actor_id')->filter()->all())->toBe([])
        ->and($entries[0]->detail)->toBe(['reason' => 'account_deleted']);
});

test('every credential fails on the very next request', function () {
    leaveAccountPurging($this->user);

    foreach ([$this->first, $this->second, $this->third] as $credential) {
        exportCall('GET', $credential, '/me')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
});

test('while purging, signing in is the uniform invalid-credentials answer and counts toward the lockout', function () {
    leaveAccountPurging($this->user);

    signInAs()->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    foreach (range(1, 4) as $attempt) {
        signInAs()->assertStatus(401);
    }

    signInAs()->assertStatus(423);
    expect(AuditEntry::query()->where('event', 'sign_in_failed')->count())->toBeGreaterThanOrEqual(5);
});

test('while purging, the deletion cannot be cancelled', function () {
    leaveAccountPurging($this->user);

    expect(app(CancelDeletion::class)->cancel($this->user->id, 'explicit'))->toBeFalse()
        ->and(User::query()->find($this->user->id)->state)->toBe('purging')
        ->and(AuditEntry::query()->where('event', 'deletion_cancelled')->count())->toBe(0);
});

test('an account that is active, or pending but not yet due, is left alone', function () {
    $active = person('active@example.test');
    $notDue = person('notdue@example.test');
    DB::table('users')->where('id', $notDue->id)->update(['state' => 'pending_deletion', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(2)]);

    app(PurgeAccount::class)->run($active->id);
    app(PurgeAccount::class)->run($notDue->id);

    expect(User::query()->find($active->id)->state)->toBe('active')
        ->and(User::query()->find($notDue->id)->state)->toBe('pending_deletion')
        ->and(DB::table('account_purges')->count())->toBe(0)
        ->and(DeviceSession::query()->where('user_id', $notDue->id)->count())->toBe(0);
});

test('the hourly run dispatches every due and every purging account, soonest first, and no one else', function () {
    Queue::fake();
    $due = dueForPurge(person('due@example.test'));
    $late = person('late@example.test');
    DB::table('users')->where('id', $late->id)->update(['state' => 'pending_deletion', 'deletion_requested_at' => now()->subDays(40), 'deletion_scheduled_for' => now()->subDays(5)]);
    $purging = person('purging@example.test');
    DB::table('users')->where('id', $purging->id)->update(['state' => 'purging', 'deletion_requested_at' => now()->subDays(40), 'deletion_scheduled_for' => now()->subDays(8)]);
    $future = person('future@example.test');
    DB::table('users')->where('id', $future->id)->update(['state' => 'pending_deletion', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDay()]);

    $this->artisan('identity:purge-accounts')->assertSuccessful();

    $pushed = Queue::pushed(PurgeAccountJob::class)->map(fn (PurgeAccountJob $job): string => $job->userId)->all();
    expect($pushed)->toBe([$purging->id, $late->id, $due->id])
        ->and(collect(app(Schedule::class)->events())->contains(fn ($event): bool => str_contains($event->command, 'identity:purge-accounts')))->toBeTrue();
});

test('the purge job is unique per account, with its attempts and timeout', function () {
    $job = new PurgeAccountJob($this->user->id);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($this->user->id)
        ->and($job->tries)->toBe(5)
        ->and($job->timeout)->toBe(1800)
        ->and($job->backoff())->toBe([60, 300, 900, 3600]);
});

test('queue retry_after outlasts the longest job timeout', function () {
    expect((int) config('queue.connections.database.retry_after'))->toBeGreaterThan(1800);
});
