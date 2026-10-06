<?php

use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Notifications\Application\Departure\NotificationsPurge;
use App\Modules\Notifications\Application\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Notifications' step of the account purge (spec 023, FR-012, FR-044).
 */

beforeEach(function () {
    Mail::fake();
    $this->user = person();
    $this->other = person('grace@example.test');

    foreach ([$this->user, $this->other] as $person) {
        registerPhone($person, 'phone-'.$person->id);
        savePrefs($person->id, ['sharing' => false]);
        app(NotificationService::class)->create(budgetDraft($person->id));
    }
});

function notificationRowsOf(string $userId): array
{
    return [
        'registrations' => DB::table('push_registrations')->where('user_id', $userId)->count(),
        'preferences' => DB::table('notification_preferences')->where('user_id', $userId)->count(),
        'notifications' => DB::table('push_notifications')->where('user_id', $userId)->count(),
        'deliveries' => DB::table('push_deliveries')->whereIn('notification_id', DB::table('push_notifications')->where('user_id', $userId)->select('id'))->count(),
    ];
}

test('the step is registered between subscriptions and exports', function () {
    $steps = array_map(fn (PurgeStep $step): string => $step->value, PurgeStep::cases());

    expect(array_search('notifications', $steps, true))->toBe(array_search('subscriptions', $steps, true) + 1)
        ->and(array_search('exports', $steps, true))->toBe(array_search('notifications', $steps, true) + 1)
        ->and(app(NotificationsPurge::class)->step())->toBe(PurgeStep::Notifications);
});

test('after a purge nothing of the person remains in the four tables, and nobody else is touched', function () {
    expect(notificationRowsOf($this->user->id))->toBe(['registrations' => 1, 'preferences' => 1, 'notifications' => 1, 'deliveries' => 1]);

    purgeAccountNow($this->user->id);

    expect(notificationRowsOf($this->user->id))->toBe(['registrations' => 0, 'preferences' => 0, 'notifications' => 0, 'deliveries' => 0])
        ->and(notificationRowsOf($this->other->id))->toBe(['registrations' => 1, 'preferences' => 1, 'notifications' => 1, 'deliveries' => 1]);
});

test('the purge leaves no registration even when the session-ended listener never ran', function () {
    Queue::fake();

    purgeAccountNow($this->user->id);

    expect(DB::table('push_registrations')->where('user_id', $this->user->id)->count())->toBe(0);
});

test('running the step twice is a no-op', function () {
    $context = purgeContextFor($this->user->id);

    app(NotificationsPurge::class)->purge($this->user->id, $context);
    app(NotificationsPurge::class)->purge($this->user->id, $context);

    expect(notificationRowsOf($this->user->id))->toBe(['registrations' => 0, 'preferences' => 0, 'notifications' => 0, 'deliveries' => 0])
        ->and(notificationRowsOf($this->other->id)['notifications'])->toBe(1);
});

test('a crash before the step resumes and finishes the purge', function () {
    purgeWithCrashAfter($this->user->id, PurgeStep::Subscriptions);

    expect(notificationRowsOf($this->user->id))->toBe(['registrations' => 0, 'preferences' => 0, 'notifications' => 0, 'deliveries' => 0])
        ->and(DB::table('users')->where('id', $this->user->id)->value('state'))->toBe('purged');
});

test('the step adds nothing to the completion entry vocabulary', function () {
    expect(app(NotificationsPurge::class)->purge($this->user->id, purgeContextFor($this->user->id))->toArray())
        ->toHaveKeys(PurgeCounts::KEYS)
        ->and(array_sum(app(NotificationsPurge::class)->purge($this->user->id, purgeContextFor($this->user->id))->toArray()))->toBe(0);
});
