<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Notifications\Application\Reminders\SendOccurrenceReminders;
use App\Modules\Notifications\Infrastructure\Models\PushNotification;
use Illuminate\Support\Collection;

/*
 * The hourly reminder pass (spec 023, FR-023, US3). The default clock is
 * 2026-10-06 10:00 UTC, 13:00 in Cairo.
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id);
    registerPhone($this->owner, 'owner-phone');
});

function runReminders(): array
{
    return app(SendOccurrenceReminders::class)->run();
}

function reminders(): Collection
{
    return PushNotification::query()->orderBy('created_at')->get();
}

test('one occurrence_due per eligible recipient, carrying the wallet and the occurrence', function () {
    $editor = person('editor@example.test');
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    registerPhone($editor, 'editor-phone');
    registerPhone($viewer, 'viewer-phone');
    $occurrence = dueOccurrence($this->wallet, $this->owner->id);

    $counts = runReminders();

    expect($counts)->toBe(['created' => 2, 'summarised' => 0, 'skipped' => 0])
        ->and(fakePush()->messagesTo('owner-phone'))->toHaveCount(1)
        ->and(fakePush()->messagesTo('editor-phone'))->toHaveCount(1)
        ->and(fakePush()->messagesTo('viewer-phone'))->toHaveCount(0);

    $message = fakePush()->messagesTo('owner-phone')[0];
    expect($message->data)->toMatchArray([
        'type' => 'occurrence_due', 'category' => 'recurring_due',
        'wallet_id' => $this->wallet->id, 'occurrence_id' => $occurrence->id,
    ])->and($message->collapseKey)->toBe('occurrence:'.$occurrence->id)
        ->and($message->priority->value)->toBe('HIGH');
});

test('running the pass again creates nothing', function () {
    dueOccurrence($this->wallet, $this->owner->id);

    runReminders();
    $second = runReminders();

    expect($second['created'])->toBe(0)->and(fakePush()->count())->toBe(1)->and(reminders())->toHaveCount(1);
});

test('before 09:00 in the recipient timezone nothing is created, and the first run after 09:00 sends', function () {
    dueOccurrence($this->wallet, $this->owner->id);

    $this->travelTo(now()->setTime(5, 59)); // 08:59 in Cairo
    expect(runReminders())->toBe(['created' => 0, 'summarised' => 0, 'skipped' => 1])->and(reminders())->toHaveCount(0);

    $this->travelTo(now()->setTime(6, 0)); // 09:00 in Cairo
    expect(runReminders()['created'])->toBe(1)->and(fakePush()->count())->toBe(1);
});

test('the hour is the recipient own, not the rule or the server', function () {
    Profile::query()->where('user_id', $this->owner->id)->update(['timezone' => 'Asia/Tokyo']);
    dueOccurrence($this->wallet, $this->owner->id);

    $this->travelTo(now()->setTime(23, 59)); // 08:59 the next morning in Tokyo, still the rule's today in Cairo
    expect(runReminders()['created'])->toBe(0);

    $this->travelTo(now()->setTime(0, 0)->addDay()); // 09:00 in Tokyo
    expect(runReminders()['skipped'])->toBe(0);
});

test('a refused automatic confirmation is reminded as occurrence_auto_confirm_refused', function () {
    $occurrence = dueOccurrence($this->wallet, $this->owner->id, rule: ['auto_confirm' => true], occurrence: ['auto_confirm_refusal' => 'not_permitted']);

    runReminders();

    $message = fakePush()->messages()[0];
    expect($message->data['type'])->toBe('occurrence_auto_confirm_refused')
        ->and($message->data['occurrence_id'])->toBe($occurrence->id)
        ->and($message->collapseKey)->toBe('occurrence:'.$occurrence->id);
});

test('an automatic occurrence nobody refused reminds nobody', function () {
    dueOccurrence($this->wallet, $this->owner->id, rule: ['auto_confirm' => true]);

    expect(runReminders()['created'])->toBe(0)->and(fakePush()->count())->toBe(0);
});

test('three occurrences are three notifications; four become one summary and four markers', function () {
    foreach (range(1, 3) as $n) {
        dueOccurrence($this->wallet, $this->owner->id);
    }
    expect(runReminders()['created'])->toBe(3)->and(fakePush()->count())->toBe(3);

    foreach (range(1, 4) as $n) {
        dueOccurrence($this->wallet, $this->owner->id);
    }
    $counts = runReminders();

    $summary = PushNotification::query()->where('type', 'occurrences_due_summary')->get();
    expect($counts)->toBe(['created' => 1, 'summarised' => 4, 'skipped' => 0])
        ->and($summary)->toHaveCount(1)
        ->and($summary[0]->data)->toBe([])
        ->and($summary[0]->dedupe_key)->toBe('summary:2026-10-06T10')
        ->and(PushNotification::query()->where('skip_reason', 'summarised')->count())->toBe(4)
        ->and(fakePush()->count())->toBe(4);

    $text = fakePush()->messages()[3];
    expect($text->data)->toBe(['schema' => '1', 'type' => 'occurrences_due_summary', 'category' => 'recurring_due', 'notification_id' => $summary[0]->id])
        ->and($text->collapseKey)->toBe('occurrences_due');

    // Each folded occurrence is reminded for good: another pass adds nothing.
    expect(runReminders()['created'])->toBe(0)->and(fakePush()->count())->toBe(4);
});

test('only occurrences not yet reminded count towards the summary threshold', function () {
    foreach (range(1, 3) as $n) {
        dueOccurrence($this->wallet, $this->owner->id);
    }
    runReminders();
    dueOccurrence($this->wallet, $this->owner->id); // the fourth, but the only new one

    $counts = runReminders();

    expect($counts['created'])->toBe(1)->and($counts['summarised'])->toBe(0)->and(fakePush()->count())->toBe(4);
});

test('an occurrence two days old that was never reminded is not reminded', function () {
    dueOccurrence($this->wallet, $this->owner->id, '2026-10-04');

    expect(runReminders()['created'])->toBe(0)->and(reminders())->toHaveCount(0);
});

test('a failing recipient does not stop the pass', function () {
    $broken = person('broken@example.test');
    $editor = person('editor@example.test');
    addMember($this->wallet->id, $broken->id, 'editor');
    addMember($this->wallet->id, $editor->id, 'editor');
    registerPhone($editor, 'editor-phone');
    Profile::query()->where('user_id', $broken->id)->update(['timezone' => 'Mars/Olympus']);
    dueOccurrence($this->wallet, $this->owner->id);
    $logs = captureLogs();

    $counts = runReminders();

    expect($counts['created'])->toBe(2)
        ->and(fakePush()->messagesTo('editor-phone'))->toHaveCount(1)
        ->and(implode("\n", array_filter($logs->getArrayCopy(), fn (string $line): bool => ! str_starts_with($line, '["debug"'))))->toContain('notifications_pass_item_failed')->not->toContain($broken->id);
});

test('one counts-only log line per pass', function () {
    dueOccurrence($this->wallet, $this->owner->id);
    $logs = captureLogs();

    runReminders();

    $lines = collect($logs->getArrayCopy())->filter(fn (string $line): bool => str_contains($line, 'notifications_pass"'));
    expect($lines)->toHaveCount(1)
        ->and($lines->first())->toContain('"created":1')
        ->and($lines->first())->not->toContain($this->owner->id)->not->toContain('owner-phone');
});

test('a category turned off skips the reminder and it is not resent when turned back on', function () {
    dueOccurrence($this->wallet, $this->owner->id);
    savePrefs($this->owner->id, ['recurring_due' => false]);
    runReminders();
    savePrefs($this->owner->id, ['recurring_due' => true]);

    runReminders();

    expect(fakePush()->count())->toBe(0)->and(reminders()->pluck('skip_reason')->all())->toBe(['category_off']);
});

test('the command runs the pass', function () {
    dueOccurrence($this->wallet, $this->owner->id);

    $this->artisan('notifications:occurrence-reminders')->assertSuccessful();

    expect(fakePush()->count())->toBe(1);
});
