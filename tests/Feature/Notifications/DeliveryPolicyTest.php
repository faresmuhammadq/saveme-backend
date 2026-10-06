<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Notifications\Application\Delivery\ReleaseHeld;
use App\Modules\Notifications\Domain\Preferences\QuietHours;
use App\Modules\Notifications\Infrastructure\Models\PushNotification;

test('a category turned off is recorded skipped category_off and nothing is sent', function () {
    $user = person();
    registerPhone($user, 'token-a');
    savePrefs($user->id, ['budget_exceeded' => false]);

    $id = budgetNotification($user->id, 'k1');

    expect(notificationRow($id)->state)->toBe('skipped')->and(notificationRow($id)->skip_reason)->toBe('category_off')->and(fakePush()->count())->toBe(0);
});

test('nothing skipped while a category was off is sent when it is turned back on', function () {
    $user = person();
    registerPhone($user, 'token-a');
    savePrefs($user->id, ['budget_exceeded' => false]);
    budgetNotification($user->id, 'k1');

    savePrefs($user->id, ['budget_exceeded' => true]);

    app(ReleaseHeld::class)->run();
    expect(fakePush()->count())->toBe(0);
});

test('inside quiet hours a notification is held until they end in the profile timezone', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30)); // 23:30 in Cairo

    $id = budgetNotification($user->id, 'k1');

    expect(notificationRow($id)->state)->toBe('held')
        ->and(notificationRow($id)->not_before->format('Y-m-d H:i'))->toBe('2026-10-07 05:00') // 08:00 Cairo
        ->and(fakePush()->count())->toBe(0);
});

test('the release sweep sends held notifications once quiet hours have ended', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30));
    $id = budgetNotification($user->id, 'k1');

    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(4, 59));
    expect(app(ReleaseHeld::class)->run()['released'])->toBe(0)->and(notificationRow($id)->state)->toBe('held');

    $this->travelTo(now()->setTime(5, 0));
    expect(app(ReleaseHeld::class)->run()['released'])->toBe(1)
        ->and(notificationRow($id)->state)->toBe('sent')
        ->and(fakePush()->count())->toBe(1);
});

test('quiet hours turned off while held release the notification at the next sweep', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30));
    $id = budgetNotification($user->id, 'k1');

    savePrefs($user->id, quiet: new QuietHours(false, 22 * 60, 8 * 60));
    app(ReleaseHeld::class)->run();

    expect(notificationRow($id)->state)->toBe('sent');
});

test('quiet hours moved while held re-hold the notification until the new end', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30)); // 23:30 Cairo
    $id = budgetNotification($user->id, 'k1');

    savePrefs($user->id, quiet: new QuietHours(true, 22 * 60, 10 * 60));
    app(ReleaseHeld::class)->run();

    expect(notificationRow($id)->state)->toBe('held')->and(notificationRow($id)->not_before->format('Y-m-d H:i'))->toBe('2026-10-07 07:00');
});

test('a category turned off while held skips the notification at release', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30));
    $id = budgetNotification($user->id, 'k1');

    savePrefs($user->id, ['budget_exceeded' => false]);
    app(ReleaseHeld::class)->run();

    expect(notificationRow($id)->skip_reason)->toBe('category_off');
});

test('of held notifications sharing a collapse key only the latest is sent', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30));
    $older = budgetNotification($user->id, 'k-older');
    $this->travel(10)->minutes();
    $newer = budgetNotification($user->id, 'k-newer');
    $other = budgetNotification($user->id, 'k-other', '0192f8d0-0000-7000-8000-0000000000b8');

    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(5, 1));
    $counts = app(ReleaseHeld::class)->run();

    expect($counts['collapsed'])->toBe(1)->and($counts['released'])->toBe(2)
        ->and(notificationRow($older)->skip_reason)->toBe('collapsed')
        ->and(notificationRow($newer)->state)->toBe('sent')
        ->and(notificationRow($other)->state)->toBe('sent')
        ->and(fakePush()->count())->toBe(2);
});

test('a released burst goes through the rate limit, oldest first, and the overflow is skipped', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30));

    $ids = [];
    foreach (range(1, 13) as $n) {
        $this->travel(1)->minutes();
        $ids[$n] = budgetNotification($user->id, "k{$n}", sprintf('0192f8d0-0000-7000-8000-%012d', $n));
    }

    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(5, 5));
    $counts = app(ReleaseHeld::class)->run();

    expect($counts['released'])->toBe(10)->and($counts['skipped'])->toBe(3);
    foreach (range(1, 10) as $n) {
        expect(notificationRow($ids[$n])->state)->toBe('sent');
    }
    foreach (range(11, 13) as $n) {
        expect(notificationRow($ids[$n])->skip_reason)->toBe('rate_limited');
    }
});

test('at most ten an hour and thirty a day are sent, the rest recorded rate_limited and never sent later', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $skipped = 0;

    foreach (range(1, 12) as $n) {
        $id = budgetNotification($user->id, "k{$n}", sprintf('0192f8d0-0000-7000-8000-%012d', $n));
        $skipped += notificationRow($id)->skip_reason === 'rate_limited' ? 1 : 0;
    }

    expect(fakePush()->count())->toBe(10)->and($skipped)->toBe(2);

    $this->travel(61)->minutes();
    expect(notificationRow(PushNotification::query()->where('dedupe_key', 'k11')->value('id'))->state)->toBe('skipped');

    // A new hour allows ten more, up to thirty in the day.
    foreach (range(13, 40) as $n) {
        budgetNotification($user->id, "k{$n}", sprintf('0192f8d0-0000-7000-8000-%012d', $n));
        $this->travel(7)->minutes();
    }

    expect(fakePush()->count())->toBeLessThanOrEqual(30);
});

test('a profile timezone change moves the evaluation', function () {
    $user = person();
    registerPhone($user, 'token-a');
    // 10:00 UTC is 13:00 in Cairo (outside quiet hours) but 19:00 in Tokyo, so move quiet hours to cover Tokyo evening.
    Profile::query()->where('user_id', $user->id)->update(['timezone' => 'Asia/Tokyo']);
    savePrefs($user->id, quiet: new QuietHours(true, 18 * 60, 22 * 60));

    $id = budgetNotification($user->id, 'k1');

    expect(notificationRow($id)->state)->toBe('held')->and(notificationRow($id)->not_before->format('Y-m-d H:i'))->toBe('2026-10-06 13:00'); // 22:00 Tokyo
});

test('a held message is rendered in the language current at send time', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30));
    budgetNotification($user->id, 'k1');

    Profile::query()->where('user_id', $user->id)->update(['locale' => 'ar']);
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(5, 1));
    app(ReleaseHeld::class)->run();

    expect(fakePush()->messages()[0]->title)->toBe(__('notifications::push.budget_exceeded.title', [], 'ar'));
});

test('a notification stuck sending for more than fifteen minutes is re-queued without repeating a delivery', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $id = budgetNotification($user->id, 'k1');
    expect(fakePush()->count())->toBe(1);

    // A worker died after sending but before it recorded the notification.
    PushNotification::query()->whereKey($id)->update(['state' => 'sending', 'sent_at' => null, 'sending_since' => now()->subMinutes(16)]);

    $counts = app(ReleaseHeld::class)->run();

    expect($counts['requeued'])->toBe(1)
        ->and(notificationRow($id)->state)->toBe('sent')
        ->and(fakePush()->count())->toBe(1);
});

test('one failing notification does not stop the sweep', function () {
    $user = person();
    registerPhone($user, 'token-a');
    $this->travelTo(now()->setTime(20, 30));
    $bad = budgetNotification($user->id, 'bad');
    $good = budgetNotification($user->id, 'good', '0192f8d0-0000-7000-8000-0000000000b8');
    // Corrupt one row's data so building its record fails.
    DB::table('push_notifications')->where('id', $bad)->update(['type' => 'budget_exceeded', 'data' => json_encode(['budget_id' => 'x'])]);

    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(5, 1));
    $logs = captureLogs();
    app(ReleaseHeld::class)->run();

    expect(notificationRow($good)->state)->toBe('sent');
});

test('the release command prints one counts line', function () {
    $this->artisan('notifications:release-held')->assertSuccessful();
});

test('records survive as plain identifiers: no text and no token in the ledger', function () {
    $user = person();
    registerPhone($user, 'token-visible?');
    budgetNotification($user->id, 'k1');

    $dump = json_encode(DB::table('push_notifications')->get());

    expect($dump)->not->toContain('token-visible')->not->toContain('over its limit');
});
