<?php

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Application\Deletion\PurgeAccount;
use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Application\Mail\AccountDeletedMail;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Identity\Domain\Events\ProfileUpdated;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use App\Modules\Wallets\Domain\Events\WalletDeleted;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * A purge that stops halfway finishes correctly (spec 022, FR-053, FR-054,
 * US5): the worker dies right after a step commits, before its completion is
 * recorded; the next run ends exactly where an uninterrupted purge ends, with
 * nothing done twice.
 */

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->events = [];
    foreach ([ProfileUpdated::class, WalletOwnershipTransferred::class, WalletDeleted::class, SubscriptionLapsed::class] as $event) {
        Event::listen($event, function () use ($event): void {
            $this->events[$event] = ($this->events[$event] ?? 0) + 1;
        });
    }
});

/**
 * A world with every kind of thing a purge touches.
 *
 * @return array<string, mixed>
 */
function resumableWorld(string $tag): array
{
    $world = exportWorld($tag);
    createBudget($world['d']->id, ['category_id' => null]);
    ownedCategory($world['d']->id, ['name' => 'Unused'.$tag]);
    $world['invitation'] = pendingInvitation($world['d']->id, $world['s']->id, "invitee{$tag}@example.test");
    $world['subscription'] = Subscription::factory()->create(['user_id' => $world['d']->id, 'provider' => 'manual']);
    $export = DataExport::factory()->create(['user_id' => $world['d']->id]);
    app(GenerateExport::class)->run($export->id);
    $world['session'] = Str::before(signInAs("ada{$tag}@example.test")->json('data.credential'), '|');
    $world['tag'] = $tag;

    return $world;
}

/**
 * What a purge did to one world, as counts and versions that must not depend
 * on whether the purge was interrupted.
 *
 * @return array<string, mixed>
 */
function purgeSignature(object $test, array $world): array
{
    $wallets = [$world['w']->id, $world['r']->id, $world['l']->id, $world['s']->id];
    $subjects = [$world['d']->id, $world['subscription']->id, $world['session'], $world['invitation']['id'], ...$wallets];
    $address = "ada{$world['tag']}@example.test";

    return [
        'audit' => DB::table('audit_entries')
            ->where(fn ($query) => $query->whereIn('subject_id', $subjects)->orWhere('actor_id', $world['d']->id))
            ->select('event', 'actor_type', DB::raw('count(*) as n'))->groupBy('event', 'actor_type')->orderBy('event')->orderBy('actor_type')->get()
            ->map(fn ($row): string => "{$row->event}/{$row->actor_type}={$row->n}")->all(),
        'activity' => DB::table('wallet_activity')->whereIn('wallet_id', $wallets)
            ->select('action', DB::raw('count(*) as n'))->groupBy('action')->orderBy('action')->get()->map(fn ($row): string => "{$row->action}={$row->n}")->all(),
        'wallets' => DB::table('wallets')->whereIn('id', $wallets)->orderBy('created_at')->get(['version', 'deleted_at'])->map(fn ($row): array => [(int) $row->version, $row->deleted_at !== null])->all(),
        'members' => DB::table('wallet_members')->whereIn('wallet_id', $wallets)->orderBy('created_at')->orderBy('id')->get(['role', 'version', 'deleted_at'])->map(fn ($row): array => [$row->role, (int) $row->version, $row->deleted_at !== null])->all(),
        'transactions' => DB::table('transactions')->whereIn('wallet_id', $wallets)->orderBy('created_at')->get(['version', 'deleted_at'])->map(fn ($row): array => [(int) $row->version, $row->deleted_at !== null])->all(),
        'invitation' => (array) DB::table('invitations')->where('id', $world['invitation']['id'])->first(['status', 'version', 'address_sealed']),
        'subscription' => DB::table('subscriptions')->where('id', $world['subscription']->id)->value('status'),
        'categories' => DB::table('categories')->where('user_id', $world['d']->id)->count(),
        'budgets' => DB::table('budgets')->where('user_id', $world['d']->id)->count(),
        'state' => DB::table('users')->where('id', $world['d']->id)->value('state'),
        'keys' => DB::table('user_keys')->where('user_id', $world['d']->id)->count(),
        'dependants' => DB::table('profiles')->where('user_id', $world['d']->id)->count() + DB::table('data_exports')->where('user_id', $world['d']->id)->count() + DB::table('device_sessions')->where('user_id', $world['d']->id)->count(),
        'progress' => DB::table('account_purges')->where('user_id', $world['d']->id)->count(),
        'completion_mails' => count(array_filter(Mail::sent(AccountDeletedMail::class)->all(), fn ($mail): bool => $mail->hasTo($address))),
        'events' => $test->events,
    ];
}

test('for every step, dying right after it commits and resuming ends exactly like an uninterrupted purge, with nothing done twice', function (PurgeStep $step) {
    $control = resumableWorld('-control');
    $this->events = [];
    purgeAccountNow($control['d']->id);
    $uninterrupted = purgeSignature($this, $control);

    $interrupted = resumableWorld('-test');
    $this->events = [];
    purgeWithCrashAfter($interrupted['d']->id, $step);
    $resumed = purgeSignature($this, $interrupted);

    expect($uninterrupted['state'])->toBe('purged')
        ->and($uninterrupted['completion_mails'])->toBe(1)
        ->and($resumed)->toBe($uninterrupted);
})->with(array_values(array_filter(PurgeStep::cases(), fn (PurgeStep $step): bool => $step !== PurgeStep::Begin)));

test('two runs at once: the second leaves the work to the first', function () {
    $world = resumableWorld('-race');
    config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);
    $other = DB::connection('pgsql_other');
    $claimed = (bool) $other->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS claimed', ['account_purge:'.$world['d']->id])->claimed;
    expect($claimed)->toBeTrue();

    purgeAccountNow($world['d']->id);

    expect(DB::table('users')->where('id', $world['d']->id)->value('state'))->toBe('pending_deletion')
        ->and(DB::table('account_purges')->count())->toBe(0);

    $other->selectOne('SELECT pg_advisory_unlock(hashtext(?))', ['account_purge:'.$world['d']->id]);
    app(PurgeAccount::class)->run($world['d']->id);

    expect(DB::table('users')->where('id', $world['d']->id)->value('state'))->toBe('purged');
});

test('a run on a purged husk, or on someone who never asked to leave, does nothing', function () {
    $world = resumableWorld('-husk');
    purgeAccountNow($world['d']->id);
    $audit = DB::table('audit_entries')->count();

    app(PurgeAccount::class)->run($world['d']->id);
    app(PurgeAccount::class)->run($world['p']->id);

    expect(DB::table('audit_entries')->count())->toBe($audit)
        ->and(DB::table('users')->where('id', $world['p']->id)->value('state'))->toBe('active');
});

test('two members of one wallet purged in sequence: the second inherits at the first\'s purge, the wallet goes at the second\'s', function () {
    $world = resumableWorld('-chain');
    $solo = ownedWallet($world['d']->id);
    addMember($solo->id, $world['p']->id, 'editor');

    purgeAccountNow($world['d']->id);
    expect(DB::table('wallet_members')->where('wallet_id', $solo->id)->where('role', 'owner')->value('user_id'))->toBe($world['p']->id)
        ->and(Wallet::query()->find($solo->id)->deleted_at)->toBeNull();

    purgeAccountNow($world['p']->id);

    expect(Wallet::query()->find($solo->id)->deleted_at)->not->toBeNull();
});
