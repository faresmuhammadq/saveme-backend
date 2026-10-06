<?php

use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Admin\Http\Livewire\Overview;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Notifications\Application\NotificationFigures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Operators see whether push works, never what it said (spec 023, US7,
 * FR-046, FR-047, P-18 rule 7).
 */

const PUSH_MARKERS = ['ZQ-PUSH-TOKEN-8841', 'Something is due today', 'A recurring item needs your confirmation', '0192f8d0-0000-7000-8000-0000000000b7'];

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
});

function pushRow(string $userId, string $state, ?string $reason = null, ?string $key = null, ?string $createdAt = null): string
{
    $id = (string) Str::uuid7();
    $at = $createdAt ?? now()->subHour()->format('Y-m-d H:i:s.uP');

    DB::table('push_notifications')->insert([
        'id' => $id, 'user_id' => $userId, 'type' => 'budget_exceeded', 'category' => 'budget_exceeded',
        'dedupe_key' => $key ?? 'k-'.$id, 'data' => json_encode(['budget_id' => '0192f8d0-0000-7000-8000-0000000000b7']),
        'collapse_key' => 'budget:0192f8d0-0000-7000-8000-0000000000b7', 'state' => $state, 'skip_reason' => $reason,
        'sent_at' => $state === 'sent' ? $at : null, 'created_at' => $at, 'updated_at' => $at,
    ]);

    return $id;
}

function seedPushLedger(string $userId): void
{
    pushRow($userId, 'sent');
    pushRow($userId, 'sent');
    pushRow($userId, 'skipped', 'category_off');
    pushRow($userId, 'failed');
    pushRow($userId, 'pending');
    pushRow($userId, 'sent', createdAt: now()->subHours(25)->format('Y-m-d H:i:s.uP')); // outside the window

    $sent = pushRow($userId, 'sent');
    DB::table('push_deliveries')->insert(['id' => (string) Str::uuid7(), 'notification_id' => $sent, 'registration_id' => (string) Str::uuid7(), 'state' => 'pruned', 'attempts' => 1, 'last_error' => 'UNREGISTERED', 'created_at' => now(), 'updated_at' => now()->subMinutes(5)]);
}

it('shows how many devices of the account can receive notifications', function () {
    $user = person('target@example.test');
    registerPhone($user, 'ZQ-PUSH-TOKEN-8841');
    registerPhone($user, 'ZQ-PUSH-TOKEN-8842');
    registerPhone(person('other@example.test'), 'ZQ-PUSH-TOKEN-8843');

    $component = Livewire::test(AccountPage::class, ['user' => $user->id]);

    expect($component->get('account.push_devices'))->toBe(2);
    $component->assertSeeHtml('data-test="count-push-devices"')->assertSee(__('admin::accounts.counts.push_devices'));
    expect(app(NotificationFigures::class)->registrationCountFor($user->id))->toBe(2);
});

it('shows zero devices as zero', function () {
    $user = person('target@example.test');

    expect(Livewire::test(AccountPage::class, ['user' => $user->id])->get('account.push_devices'))->toBe(0);
});

it('shows platform counts for the last 24 hours on the overview', function () {
    seedPushLedger(person('target@example.test')->id);

    $component = Livewire::test(Overview::class);

    expect($component->get('push'))->toBe(['created' => 6, 'sent' => 3, 'skipped' => 1, 'failed' => 1, 'pruned' => 1]);
    $component->assertSeeHtml('data-test="push-figures"')
        ->assertSeeHtml('data-test="push-created"')
        ->assertSeeHtml('data-test="push-pruned"');
});

it('shows no token, no text, no identifier and no per-person list, in either language', function (string $locale) {
    $user = person('target@example.test');
    registerPhone($user, 'ZQ-PUSH-TOKEN-8841');
    seedPushLedger($user->id);

    $overview = $this->withSession([ResolveAdminLocale::SESSION_KEY => $locale])->get(route('admin.overview'))->assertOk()->getContent();
    $account = $this->withSession([ResolveAdminLocale::SESSION_KEY => $locale])->get(route('admin.accounts.show', ['user' => $user->id]))->assertOk()->getContent();

    foreach (PUSH_MARKERS as $marker) {
        expect($overview)->not->toContain($marker)->and($account)->not->toContain($marker);
    }

    expect($overview)->not->toContain($user->id)->not->toContain('target@example.test');

    $state = json_encode(Livewire::test(Overview::class)->snapshot);
    foreach (PUSH_MARKERS as $marker) {
        expect($state)->not->toContain($marker);
    }
})->with(['en', 'ar']);

it('is authorised per request: without an operator the overview and the counts are not served', function () {
    seedPushLedger(person('target@example.test')->id);
    $this->flushSession();

    $this->get(route('admin.overview'))->assertRedirect();
    $this->get(route('admin.accounts.show', ['user' => (string) Str::uuid7()]))->assertRedirect();
});
