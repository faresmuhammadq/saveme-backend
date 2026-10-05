<?php

use App\Modules\Admin\Application\Notifications\NotificationReadMarks;
use App\Modules\Admin\Application\OperatorNotificationQueryService;
use App\Modules\Admin\Http\Livewire\OperatorNotificationBell;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Read marks belong to one operator each (spec 021, US6; FR-050 to FR-053).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->first] = makeOperator();
    ['operator' => $this->second] = makeOperator();
    $this->marks = app(NotificationReadMarks::class);
    $this->service = app(OperatorNotificationQueryService::class);
});

function notificationAt(int $daysAgo, ?string $userId = null): string
{
    $id = (string) Str::uuid7();
    DB::table('operator_notifications')->insert([
        'id' => $id, 'kind' => 'deletion_cancelled_by_sign_in', 'subject_user_id' => $userId ?? (string) Str::uuid7(),
        'occurred_at' => now()->subDays($daysAgo), 'payload' => '{}', 'source_event_id' => (string) Str::uuid7(), 'created_at' => now(),
    ]);

    return $id;
}

test('unread is the last 7 days this operator has not marked read; older never counts', function () {
    $recent = notificationAt(1);
    notificationAt(3);
    notificationAt(10);

    expect($this->service->unreadCountFor($this->first->id))->toBe(2);

    $this->marks->markRead($this->first->id, $recent);

    expect($this->service->unreadCountFor($this->first->id))->toBe(1)
        ->and($this->service->unreadCountFor($this->second->id))->toBe(2);
});

test('marking one read is for this operator only and is idempotent', function () {
    $id = notificationAt(1);

    $this->marks->markRead($this->first->id, $id);
    $this->marks->markRead($this->first->id, $id);

    expect(DB::table('operator_notification_reads')->count())->toBe(1)
        ->and(collect($this->service->latestFor($this->first->id))->first()->read)->toBeTrue()
        ->and(collect($this->service->latestFor($this->second->id))->first()->read)->toBeFalse();
});

test('an unknown notification is ignored', function () {
    $this->marks->markRead($this->first->id, (string) Str::uuid7());

    expect(DB::table('operator_notification_reads')->count())->toBe(0);
});

test('mark all read marks this operator\'s unread only, twice the same', function () {
    notificationAt(1);
    notificationAt(2);
    notificationAt(20);

    $this->marks->markAllRead($this->first->id);
    $this->marks->markAllRead($this->first->id);

    expect($this->service->unreadCountFor($this->first->id))->toBe(0)
        ->and($this->service->unreadCountFor($this->second->id))->toBe(2)
        ->and(DB::table('operator_notification_reads')->where('operator_id', $this->first->id)->count())->toBe(2);
});

test('the bell opens an item: marks it read for this operator and follows to the account', function () {
    signInOperator($this->first);
    $user = User::factory()->create();
    $id = notificationAt(1, $user->id);
    notificationAt(2);

    Livewire::test(OperatorNotificationBell::class)
        ->assertSet('unread', 2)
        ->assertSeeHtml('data-test="notification-'.$id.'"')
        ->call('open', $id)
        ->assertRedirect(route('admin.accounts.show', ['user' => $user->id]));

    expect($this->service->unreadCountFor($this->first->id))->toBe(1)
        ->and($this->service->unreadCountFor($this->second->id))->toBe(2);
});

test('the bell marks all read and shows the control only while something is unread', function () {
    signInOperator($this->first);
    notificationAt(1);
    notificationAt(2);

    Livewire::test(OperatorNotificationBell::class)
        ->assertSeeHtml('data-test="notifications-mark-all"')
        ->call('markAllRead')
        ->assertSet('unread', 0)
        ->assertDontSeeHtml('data-test="notifications-mark-all"');
});

test('the bell ignores an id it does not list', function () {
    signInOperator($this->first);
    $other = notificationAt(1);

    Livewire::test(OperatorNotificationBell::class)
        ->set('items', [])
        ->call('open', $other)
        ->assertNoRedirect();

    expect(DB::table('operator_notification_reads')->count())->toBe(0);
});

test('read marks write no audit entry', function () {
    $id = notificationAt(1);
    $before = DB::table('audit_entries')->count();

    $this->marks->markRead($this->first->id, $id);
    $this->marks->markAllRead($this->first->id);

    expect(DB::table('audit_entries')->count())->toBe($before);
});
