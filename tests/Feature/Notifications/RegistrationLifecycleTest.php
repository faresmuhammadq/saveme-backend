<?php

use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Sessions\EndSessions;
use App\Modules\Identity\Application\Sessions\ExpireIdleSessions;
use App\Modules\Identity\Domain\Events\DeviceSessionEnded;
use App\Modules\Identity\Domain\Sessions\EndReason;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Application\PruneOldRecords;
use App\Modules\Notifications\Infrastructure\Models\PushNotification;
use App\Modules\Notifications\Infrastructure\Models\PushRegistration;

test('every end reason removes the registration through DeviceSessionEnded', function (EndReason $reason) {
    $user = person();
    $sessionId = registerPhone($user, 'token-a');
    $survivor = registerPhone($user, 'token-b');

    event(new DeviceSessionEnded(...Events::stamp(), userId: $user->id, sessionId: $sessionId, reason: $reason->value));

    expect(PushRegistration::query()->pluck('session_id')->all())->toBe([$survivor]);
})->with(EndReason::cases());

test('the listener is idempotent when the event is delivered twice', function () {
    $user = person();
    $sessionId = registerPhone($user, 'token-a');
    $event = new DeviceSessionEnded(...Events::stamp(), userId: $user->id, sessionId: $sessionId, reason: 'signed_out');

    event($event);
    event($event);

    expect(PushRegistration::query()->count())->toBe(0);
});

test('signing out, ending one session and ending all the others each drop their registrations', function () {
    $user = person();
    $current = registerPhone($user, 'token-current');
    $other = registerPhone($user, 'token-other');
    $third = registerPhone($user, 'token-third');
    $ends = app(EndSessions::class);

    $ends->endOne($user->id, $current, $other);
    expect(PushRegistration::query()->pluck('session_id')->sort()->values()->all())->toBe(collect([$current, $third])->sort()->values()->all());

    $ends->endOthers($user->id, $current);
    expect(PushRegistration::query()->pluck('session_id')->all())->toBe([$current]);

    $ends->signOut($user->id, $current);
    expect(PushRegistration::query()->count())->toBe(0);
});

test('password change and reset end sessions and their registrations', function () {
    $user = person();
    registerPhone($user, 'token-a');
    registerPhone($user, 'token-b');

    app(EndSessions::class)->endAllFor($user->id, EndReason::PasswordChanged);

    expect(PushRegistration::query()->count())->toBe(0);
});

test('an idle session that expires drops its registration', function () {
    $user = person();
    $sessionId = registerPhone($user, 'token-a');
    DeviceSession::query()->whereKey($sessionId)->update(['signed_in_at' => now()->subDays(100), 'last_used_at' => now()->subDays(100)]);

    app(ExpireIdleSessions::class)->handle();

    expect(PushRegistration::query()->count())->toBe(0);
});

test('a delivery after the session ended but before the listener ran sends nothing', function () {
    $user = person();
    $sessionId = registerPhone($user, 'token-a');
    // The row is ended directly, as if DeviceSessionEnded were still waiting in the queue.
    DeviceSession::query()->whereKey($sessionId)->update(['state' => 'revoked', 'end_reason' => 'signed_out', 'ended_at' => now()]);

    $id = app(NotificationService::class)->create(budgetDraft($user->id));

    expect(fakePush()->count())->toBe(0)
        ->and(PushNotification::query()->findOrFail($id)->skip_reason)->toBe('no_device')
        ->and(PushRegistration::query()->count())->toBe(0);
});

test('prune deletes registrations idle for 270 days and keeps 269', function () {
    $user = person();
    $idle = registerPhone($user, 'token-idle');
    $recent = registerPhone($user, 'token-recent');
    PushRegistration::query()->where('session_id', $idle)->update(['refreshed_at' => now()->subDays(270)->subMinute()]);
    PushRegistration::query()->where('session_id', $recent)->update(['refreshed_at' => now()->subDays(269)]);

    $counts = app(PruneOldRecords::class)->run();

    expect($counts['registrations'])->toBe(1)
        ->and(PushRegistration::query()->pluck('session_id')->all())->toBe([$recent]);
});

test('prune deletes notification records older than 30 days with their deliveries', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $old = app(NotificationService::class)->create(budgetDraft($user->id, 'old'));
    $new = app(NotificationService::class)->create(budgetDraft($user->id, 'new'));
    PushNotification::query()->whereKey($old)->update(['created_at' => now()->subDays(30)->subMinute()]);

    $counts = app(PruneOldRecords::class)->run();

    expect($counts['notifications'])->toBe(1)
        ->and(PushNotification::query()->pluck('id')->all())->toBe([$new])
        ->and(DB::table('push_deliveries')->where('notification_id', $old)->count())->toBe(0)
        ->and(DB::table('push_deliveries')->where('notification_id', $new)->count())->toBe(1);
});

test('the prune command runs and logs one counts line without ids', function () {
    $logs = captureLogs();

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(implode("\n", $logs->getArrayCopy()))->toContain('notifications_pruned');
});
