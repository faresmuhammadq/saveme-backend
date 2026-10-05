<?php

use App\Modules\Admin\Application\Listeners\NotifyOperatorsOfStalledPurge;
use App\Modules\Admin\Application\Mail\OperatorNotificationMail;
use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Admin\Http\Livewire\Overview;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Identity\Application\Deletion\StartDuePurges;
use App\Modules\Identity\Application\Operators\AccountDirectory;
use App\Modules\Identity\Domain\Events\AccountPurgeStalled;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Operators see that an account is leaving, and nothing about how (spec 022,
 * FR-061 to FR-063; P-18 rule 8): a state, a count, a notification.
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
});

function leavingAccount(string $state, string $email = 'leaving@example.test'): User
{
    $world = exportWorld();
    $user = $world['d'];
    DB::table('wallets')->where('id', $world['w']->id)->update(['name' => 'ZQ-SECRET-WALLET']);

    $dates = ['deletion_requested_at' => now()->subDays(29), 'deletion_scheduled_for' => now()->addDay()];
    match ($state) {
        'pending_deletion' => User::query()->whereKey($user->id)->update(['state' => 'pending_deletion', ...$dates]),
        'purging' => User::query()->whereKey($user->id)->update(['state' => 'purging', ...$dates]),
        'purged' => User::query()->whereKey($user->id)->update(['state' => 'purged', 'purged_at' => now(), 'email_ciphertext' => null, 'email_lookup' => null, 'password_hash' => null]),
    };

    return $user;
}

it('shows "Deletion pending" with the date for a pending account, and nothing about successors, wallets or exports', function () {
    $user = leavingAccount('pending_deletion');

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSeeHtml('data-test="state-pending_deletion"')
        ->assertSee('Deletion pending')
        ->assertSee(now()->addDay()->format('Y-m-d'))
        ->assertDontSee('ZQ-SECRET-WALLET')
        ->assertDontSee('successor', false)
        ->assertDontSee('Former member')
        ->assertDontSeeHtml('data-test="export');
});

it('shows "Deleting" for an account being purged, with the id and nothing else', function () {
    $user = leavingAccount('purging');

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSee($user->id)
        ->assertSeeHtml('data-test="state-purging"')
        ->assertSee('Deleting')
        ->assertSeeHtml('data-test="purging-note"')
        ->assertDontSee('ZQ-SECRET-WALLET')
        ->assertDontSeeHtml('data-test="grant"')
        ->assertDontSeeHtml('data-test="plan-card"')
        ->assertDontSeeHtml('data-test="counts-card"');
});

it('shows "Deleted" for a husk, in Arabic too', function () {
    $user = leavingAccount('purged');

    Livewire::test(AccountPage::class, ['user' => $user->id])->assertSeeHtml('data-test="state-purged"')->assertSee('Deleted')->assertDontSee('ZQ-SECRET-WALLET');

    $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get(route('admin.accounts.show', ['user' => $user->id]))->assertOk()->assertSee('محذوف');
});

it('has an Arabic word for Deleting', function () {
    $user = leavingAccount('purging');

    $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get(route('admin.accounts.show', ['user' => $user->id]))->assertOk()->assertSee('قيد الحذف');
});

it('looks a purging or purged account up by id but never by address', function () {
    $purging = leavingAccount('purging');

    expect(app(AccountDirectory::class)->byId($purging->id)->state)->toBe('purging')
        ->and(app(AccountDirectory::class)->byAddress('ada@example.test'))->toBeNull();
});

it('counts accounts pending deletion under the anonymity floor, and leaves purging and purged out of every other figure', function () {
    User::factory()->count(12)->verified()->create();
    User::factory()->count(12)->pendingDeletion()->create();
    foreach (['purging', 'purged'] as $state) {
        foreach (range(1, 3) as $n) {
            $user = User::factory()->verified()->create();
            DB::table('users')->where('id', $user->id)->update($state === 'purging'
                ? ['state' => 'purging', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()]
                : ['state' => 'purged', 'purged_at' => now()]);
        }
    }

    Livewire::test(Overview::class)
        ->assertSet('figures.pending_deletion', 12)
        ->assertSet('figures.accounts', 24)
        ->assertSet('figures.verified', 12)
        ->assertSet('figures.new_7_days', 24);
});

it('raises one notification, once, when a purge has run for more than 24 hours', function () {
    Mail::fake();
    Queue::fake();
    $user = leavingAccount('purging');
    DB::table('account_purges')->insert(['user_id' => $user->id, 'started_at' => now()->subHours(25), 'last_step' => 'begin', 'attempts' => 3, 'updated_at' => now()]);
    $recipients = config('admin.notifications.recipients');

    $first = app(StartDuePurges::class)->run();
    $second = app(StartDuePurges::class)->run();

    expect($first['stalled'])->toBe(1)->and($second['stalled'])->toBe(0)
        ->and(DB::table('account_purges')->where('user_id', $user->id)->value('stall_notified_at'))->not->toBeNull();
});

it('does not raise it for a purge younger than 24 hours', function () {
    Queue::fake();
    $user = leavingAccount('purging');
    DB::table('account_purges')->insert(['user_id' => $user->id, 'started_at' => now()->subHours(23), 'last_step' => 'begin', 'attempts' => 1, 'updated_at' => now()]);

    expect(app(StartDuePurges::class)->run()['stalled'])->toBe(0);
});

it('stores one account_purge_stalled notification with identifiers only, and a redelivered event adds nothing', function () {
    Mail::fake();
    $event = new AccountPurgeStalled('0192f700-0000-7000-8000-0000000000aa', now()->utc()->format('Y-m-d\TH:i:s.u\Z'), '0192f700-0000-7000-8000-0000000000bb', now()->subDay()->utc()->format('Y-m-d\TH:i:s.u\Z'));

    app(NotifyOperatorsOfStalledPurge::class)->handle($event);
    app(NotifyOperatorsOfStalledPurge::class)->handle($event);

    $rows = DB::table('operator_notifications')->where('kind', 'account_purge_stalled')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->subject_user_id)->toBe('0192f700-0000-7000-8000-0000000000bb')
        ->and(array_keys(json_decode($rows[0]->payload, true)))->toBe(['started_at'])
        ->and($rows[0]->source_event_id)->toBe($event->eventId);
    Mail::assertQueuedCount(count(config('admin.notifications.recipients')));
});

it('publishes the stall event after commit and the listener is queued', function () {
    Event::fake([AccountPurgeStalled::class]);
    $user = leavingAccount('purging');
    DB::table('account_purges')->insert(['user_id' => $user->id, 'started_at' => now()->subHours(30), 'last_step' => 'begin', 'attempts' => 2, 'updated_at' => now()]);
    Queue::fake();

    app(StartDuePurges::class)->run();

    Event::assertDispatched(AccountPurgeStalled::class, fn (AccountPurgeStalled $event): bool => $event->userId === $user->id);
    expect(NotifyOperatorsOfStalledPurge::class)->toImplement(ShouldQueue::class);
});

it('renders the stalled notification and its mail in both languages', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $mail = new OperatorNotificationMail('0192f700-0000-7000-8000-0000000000bb', now()->utc()->format('Y-m-d\TH:i:s.u\Z'), 'account_purge_stalled');

        expect($mail->render())->toContain('0192f700-0000-7000-8000-0000000000bb')
            ->and(__('admin::notifications.kinds.account_purge_stalled.title'))->not->toBe('admin::notifications.kinds.account_purge_stalled.title');
    }
});
