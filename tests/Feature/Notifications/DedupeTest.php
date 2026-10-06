<?php

use App\Modules\Notifications\Application\Delivery\DeliverNotification;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use App\Modules\Notifications\Infrastructure\Models\PushNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;

test('the same draft twice makes one row and one job', function () {
    $user = person();
    registerPhone($user, 'token-a');
    Queue::fake();
    $service = app(NotificationService::class);

    $first = $service->create(budgetDraft($user->id));
    $second = $service->create(budgetDraft($user->id));

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(PushNotification::query()->count())->toBe(1);
    Queue::assertPushed(DeliverNotification::class, 1);
});

test('the same key for two people makes two notifications', function () {
    $ada = person();
    $grace = person('grace@example.test');
    Queue::fake();
    $service = app(NotificationService::class);

    $service->create(budgetDraft($ada->id));
    $service->create(budgetDraft($grace->id));

    expect(PushNotification::query()->count())->toBe(2);
});

test('a different key for the same person is a new notification', function () {
    $user = person();
    Queue::fake();
    $service = app(NotificationService::class);

    $service->create(budgetDraft($user->id, 'budget_exceeded:b1:2026-10-01'));
    $service->create(budgetDraft($user->id, 'budget_exceeded:b1:2026-11-01'));

    expect(PushNotification::query()->count())->toBe(2);
});

test('a draft whose key exists does nothing even when the first one was skipped', function () {
    $user = person();
    $service = app(NotificationService::class);

    $first = $service->create(budgetDraft($user->id));
    $second = $service->create(budgetDraft($user->id));

    expect(PushNotification::query()->findOrFail($first)->state)->toBe('skipped')
        ->and($second)->toBeNull()
        ->and(PushNotification::query()->count())->toBe(1);
});

test('two processes creating the same notification at once make one row', function () {
    $user = person();
    $draft = budgetDraft($user->id);

    // The unique key is the arbiter: an insert that loses the race does nothing and returns null.
    $repository = app(NotificationRepository::class);
    $winner = $repository->createIfAbsent($draft);
    $loser = $repository->createIfAbsent($draft);

    expect($winner)->not->toBeNull()->and($loser)->toBeNull()->and(PushNotification::query()->count())->toBe(1);

    // And at the database: the same pair cannot be inserted twice by any path.
    expect(fn () => PushNotification::query()->create([
        'user_id' => $user->id, 'type' => 'budget_exceeded', 'category' => 'budget_exceeded', 'dedupe_key' => $draft->dedupeKey,
        'data' => ['budget_id' => 'x'], 'collapse_key' => 'budget:x', 'state' => 'pending',
    ]))->toThrow(UniqueConstraintViolationException::class);
});
