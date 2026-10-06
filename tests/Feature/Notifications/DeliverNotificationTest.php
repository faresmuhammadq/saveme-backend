<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Application\Delivery\DeliverNotification;
use App\Modules\Notifications\Application\Delivery\NotificationDelivery;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Infrastructure\Models\PushDelivery;
use App\Modules\Notifications\Infrastructure\Models\PushNotification;
use App\Modules\Notifications\Infrastructure\Models\PushRegistration;

test('a notification created in the daytime is delivered through the queue and recorded sent', function () {
    $user = person();
    registerPhone($user, 'token-a');

    $id = app(NotificationService::class)->create(budgetDraft($user->id));

    $row = PushNotification::query()->findOrFail($id);
    expect($row->state)->toBe('sent')
        ->and($row->sent_at)->not->toBeNull()
        ->and(fakePush()->count())->toBe(1)
        ->and(PushDelivery::query()->where('notification_id', $id)->value('state'))->toBe('sent');

    $message = fakePush()->messagesTo('token-a')[0];
    expect($message->type)->toBe(MessageType::BudgetExceeded)
        ->and($message->channel)->toBe('budget_exceeded')
        ->and($message->collapseKey)->toBe('budget:0192f8d0-0000-7000-8000-0000000000b7')
        ->and($message->data)->toBe([
            'schema' => '1', 'type' => 'budget_exceeded', 'category' => 'budget_exceeded',
            'notification_id' => $id, 'budget_id' => '0192f8d0-0000-7000-8000-0000000000b7',
        ])
        ->and($message->title)->toBe('A budget is over its limit');
});

test('the text is rendered at send time in the profile language', function () {
    $user = person();
    Profile::query()->where('user_id', $user->id)->update(['locale' => 'ar']);
    registerPhone($user, 'token-a');

    app(NotificationService::class)->create(budgetDraft($user->id));

    expect(fakePush()->messages()[0]->title)->toBe(__('notifications::push.budget_exceeded.title', [], 'ar'))
        ->and(fakePush()->messages()[0]->title)->not->toBe(__('notifications::push.budget_exceeded.title', [], 'en'));
});

test('an invalid token deletes its registration at once and is not retried', function () {
    $user = person();
    $sessionId = registerPhone($user, 'token-a');
    fakePush()->script('token-a', GatewayOutcome::invalidToken('UNREGISTERED'));
    $id = pendingNotification($user->id);

    expect(deliver($id))->toBeNull();

    expect(PushRegistration::query()->where('session_id', $sessionId)->exists())->toBeFalse()
        ->and(PushDelivery::query()->where('notification_id', $id)->value('state'))->toBe('pruned')
        ->and(stateOf($id))->toBe('failed')
        ->and(fakePush()->count())->toBe(1);

    expect(deliver($id))->toBeNull()->and(fakePush()->count())->toBe(1);
});

test('a transient failure is retried and then succeeds', function () {
    $user = person();
    registerPhone($user, 'token-a');
    fakePush()->script('token-a', GatewayOutcome::retryLater(null, 'UNAVAILABLE'), GatewayOutcome::accepted());
    $id = pendingNotification($user->id);

    expect(deliver($id, 1))->toBe(0)->and(stateOf($id))->toBe('sending')
        ->and(PushDelivery::query()->where('notification_id', $id)->value('last_error'))->toBe('UNAVAILABLE');

    expect(deliver($id, 2))->toBeNull()
        ->and(stateOf($id))->toBe('sent')
        ->and(fakePush()->count())->toBe(2)
        ->and(PushDelivery::query()->where('notification_id', $id)->value('attempts'))->toBe(2);
});

test('the longer of the backoff and the provider Retry-After wins', function () {
    $user = person();
    registerPhone($user, 'token-a');
    fakePush()->script('token-a', GatewayOutcome::retryLater(120, 'QUOTA_EXCEEDED'));
    $id = pendingNotification($user->id);

    $job = (new DeliverNotification($id))->withFakeQueueInteractions();
    $job->handle(app(NotificationDelivery::class));

    $job->assertReleased(120);
});

test('the backoff steps are 10 seconds, 1 minute, 5 minutes and 15 minutes over five tries', function () {
    $job = new DeliverNotification('x');

    expect($job->backoff())->toBe([10, 60, 300, 900])->and($job->tries)->toBe(5)->and($job->queue)->toBe('notifications');
});

test('five transient failures end in failed, and the last attempt does not ask for another', function () {
    $user = person();
    registerPhone($user, 'token-a');
    fakePush()->script('token-a', ...array_fill(0, 5, GatewayOutcome::retryLater(null, 'UNAVAILABLE')));
    $id = pendingNotification($user->id);

    foreach ([1, 2, 3, 4] as $attempt) {
        expect(deliver($id, $attempt))->toBe(0);
    }

    expect(deliver($id, 5))->toBeNull()
        ->and(stateOf($id))->toBe('failed')
        ->and(PushDelivery::query()->where('notification_id', $id)->value('state'))->toBe('failed')
        ->and(PushRegistration::query()->count())->toBe(1);
});

test('a rerun after a partial send only sends to the device that is not done', function () {
    $user = person();
    registerPhone($user, 'token-a');
    registerPhone($user, 'token-b');
    fakePush()->script('token-b', GatewayOutcome::retryLater(null, 'UNAVAILABLE'));
    $id = pendingNotification($user->id);

    expect(deliver($id, 1))->toBe(0);
    expect(deliver($id, 2))->toBeNull();

    expect(fakePush()->messagesTo('token-a'))->toHaveCount(1)
        ->and(fakePush()->messagesTo('token-b'))->toHaveCount(2)
        ->and(stateOf($id))->toBe('sent');
});

test('two devices, one pruned, is still sent', function () {
    $user = person();
    registerPhone($user, 'token-a');
    registerPhone($user, 'token-b');
    fakePush()->script('token-a', GatewayOutcome::invalidToken('UNREGISTERED'));
    $id = pendingNotification($user->id);

    deliver($id);

    expect(stateOf($id))->toBe('sent')->and(PushRegistration::query()->count())->toBe(1);
});

test('a person with no device is recorded skipped no_device', function () {
    $user = person();
    $id = app(NotificationService::class)->create(budgetDraft($user->id));

    $row = PushNotification::query()->findOrFail($id);
    expect($row->state)->toBe('skipped')->and($row->skip_reason)->toBe('no_device')->and(fakePush()->count())->toBe(0);
});

test('a session that ended is dropped at send time and nothing is sent to it', function () {
    $user = person();
    $sessionId = registerPhone($user, 'token-a');
    DeviceSession::query()->whereKey($sessionId)->update(['state' => 'revoked', 'end_reason' => 'signed_out', 'ended_at' => now()]);
    $id = pendingNotification($user->id);

    deliver($id);

    expect(fakePush()->count())->toBe(0)
        ->and(PushRegistration::query()->where('session_id', $sessionId)->exists())->toBeFalse()
        ->and(PushNotification::query()->findOrFail($id)->skip_reason)->toBe('no_device');
});

test('an INVALID_ARGUMENT that is not about the token fails the delivery and keeps the registration', function () {
    $user = person();
    registerPhone($user, 'token-a');
    fakePush()->script('token-a', GatewayOutcome::failed('INVALID_ARGUMENT'));
    $id = pendingNotification($user->id);

    deliver($id);

    expect(stateOf($id))->toBe('failed')
        ->and(PushRegistration::query()->count())->toBe(1)
        ->and(PushDelivery::query()->where('notification_id', $id)->value('last_error'))->toBe('INVALID_ARGUMENT');
});

test('with the disabled gateway every notification is skipped disabled', function () {
    $user = person();
    registerPhone($user, 'token-a');
    fakePush()->delivers = false;

    $id = app(NotificationService::class)->create(budgetDraft($user->id));

    expect(PushNotification::query()->findOrFail($id)->skip_reason)->toBe('disabled')->and(fakePush()->count())->toBe(0);
});

test('a notification to an account being purged is skipped inactive_account', function () {
    $user = User::factory()->pendingDeletion()->create();
    Profile::factory()->create(['user_id' => $user->id]);
    User::query()->whereKey($user->id)->update(['state' => 'purging']);

    $id = app(NotificationService::class)->create(budgetDraft($user->id));

    expect(PushNotification::query()->findOrFail($id)->skip_reason)->toBe('inactive_account');
});

test('a pending deletion still receives', function () {
    $user = User::factory()->pendingDeletion()->create();
    Profile::factory()->create(['user_id' => $user->id]);
    registerPhone($user, 'token-a');

    $id = app(NotificationService::class)->create(budgetDraft($user->id));

    expect(stateOf($id))->toBe('sent');
});

test('the job payload is the notification id and nothing else', function () {
    $user = person();
    registerPhone($user, 'token-secret-in-queue');
    $id = pendingNotification($user->id);

    $job = new DeliverNotification($id);
    $serialised = serialize($job);

    expect($serialised)->toContain($id)->and($serialised)->not->toContain('token-secret-in-queue')->and($job->uniqueId())->toBe($id);
});

test('a job that fails for good marks its notification failed', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $id = pendingNotification($user->id);
    PushNotification::query()->whereKey($id)->update(['state' => 'sending', 'sending_since' => now()]);

    (new DeliverNotification($id))->failed(new RuntimeException('boom'));

    expect(stateOf($id))->toBe('failed');
});
