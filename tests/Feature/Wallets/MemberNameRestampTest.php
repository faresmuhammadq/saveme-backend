<?php

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Domain\Events\ProfileUpdated;
use App\Modules\Wallets\Application\Activity\WalletActivity as ActivityRecorder;
use App\Modules\Wallets\Application\Membership\Listeners\ResendMemberNames;
use App\Modules\Wallets\Application\Membership\MembershipService;
use App\Modules\Wallets\Application\Membership\RestampMemberNames;
use App\Modules\Wallets\Application\WalletScopeWrites;
use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * A display-name change re-sends the person's live memberships on each
 * wallet feed (spec 017, US2; ADR-0021): only change_seq moves, after
 * commit, one wallet lock per unit of work, 50 wallets per run.
 */

/**
 * The partner's view of the renamer's membership on a wallet feed above a cursor.
 *
 * @return array<string, mixed>|null
 */
function memberRowOnFeed(string $credential, string $walletId, ?string $cursor, string $memberUserId): ?array
{
    $rows = readFeedFully($credential, $walletId, $cursor)['rows'];

    return collect($rows)->where('type', 'wallet_member')->firstWhere('record.member_user_id', $memberUserId)['record'] ?? null;
}

/**
 * @return array{version: int, updated_at: string, member_change_seq: int, change_seq: int}
 */
function storedMembership(string $walletId, string $userId): array
{
    $row = DB::table('wallet_members')->where('wallet_id', $walletId)->where('user_id', $userId)->whereNull('deleted_at')->first();

    return ['version' => (int) $row->version, 'updated_at' => (string) $row->updated_at, 'member_change_seq' => (int) $row->member_change_seq, 'change_seq' => (int) $row->change_seq];
}

beforeEach(function () {
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    [$this->grace, $this->graceCredential] = walletCaller('grace@example.test');
    $this->first = ownedWallet($this->grace->id);
    $this->second = ownedWallet($this->grace->id);
    addMember($this->first->id, $this->ada->id, 'editor');
    addMember($this->second->id, $this->ada->id, 'viewer');
    $this->baseline = DB::transactionLevel();
});

test('a rename re-sends the membership on every shared wallet feed with the same version and the new name', function () {
    $cursors = [$this->first->id => readFeedFully($this->graceCredential, $this->first->id)['cursor'], $this->second->id => readFeedFully($this->graceCredential, $this->second->id)['cursor']];
    $userCursor = readFeedFully($this->adaCredential, 'user')['cursor'];
    $before = [$this->first->id => storedMembership($this->first->id, $this->ada->id), $this->second->id => storedMembership($this->second->id, $this->ada->id)];
    $walletVersions = [$this->first->id => $this->first->fresh()->version];

    saveProfile($this->adaCredential, profileBody(['display_name' => 'Ada Lovelace']))->assertOk();

    foreach ([$this->first, $this->second] as $wallet) {
        $row = memberRowOnFeed($this->graceCredential, $wallet->id, $cursors[$wallet->id], $this->ada->id);
        $after = storedMembership($wallet->id, $this->ada->id);

        expect($row)->not->toBeNull()
            ->and($row['display_name'])->toBe('Ada Lovelace')
            ->and($row['version'])->toBe($before[$wallet->id]['version'])
            ->and($after['change_seq'])->toBeGreaterThan($before[$wallet->id]['change_seq'])
            ->and($after['updated_at'])->toBe($before[$wallet->id]['updated_at'])
            ->and($after['member_change_seq'])->toBe($before[$wallet->id]['member_change_seq']);

        $walletRow = collect(readFeedFully($this->graceCredential, $wallet->id, $cursors[$wallet->id])['rows'])->firstWhere('type', 'wallet');
        expect($walletRow)->not->toBeNull();
    }

    expect($this->first->fresh()->version)->toBe($walletVersions[$this->first->id])
        ->and(collect(readFeedFully($this->adaCredential, 'user', $userCursor)['rows'])->where('type', 'wallet_member')->all())->toBe([]);
});

test('clearing the name re-sends with a null display name', function () {
    saveProfile($this->adaCredential, profileBody(['display_name' => 'Ada']))->assertOk();
    $cursor = readFeedFully($this->graceCredential, $this->first->id)['cursor'];

    saveProfile($this->adaCredential, profileBody(['display_name' => null], 2))->assertOk();

    expect(memberRowOnFeed($this->graceCredential, $this->first->id, $cursor, $this->ada->id)['display_name'])->toBeNull();
});

test('a timezone-only, an identical and a stale save re-stamp nothing', function () {
    saveProfile($this->adaCredential, profileBody(['display_name' => 'Ada']))->assertOk();
    $before = storedMembership($this->first->id, $this->ada->id);

    saveProfile($this->adaCredential, profileBody(['display_name' => 'Ada', 'timezone' => 'Asia/Tokyo'], 2))->assertOk();
    saveProfile($this->adaCredential, profileBody(['display_name' => 'Ada', 'timezone' => 'Asia/Tokyo'], 3))->assertOk();
    saveProfile($this->adaCredential, profileBody(['display_name' => 'Zed'], 1))->assertStatus(409);

    expect(storedMembership($this->first->id, $this->ada->id))->toBe($before);
});

test('an ended membership and a deleted wallet are not re-stamped, an archived wallet is', function () {
    $left = ownedWallet($this->grace->id);
    addMember($left->id, $this->ada->id, 'editor');
    app(MembershipService::class)->leave($left->id, $this->ada->id);
    $deleted = ownedWallet($this->grace->id, ['deleted_at' => now()]);
    addMember($deleted->id, $this->ada->id, 'editor');
    $archived = ownedWallet($this->grace->id, ['archived_at' => now()]);
    addMember($archived->id, $this->ada->id, 'editor');
    $stamps = fn (string $walletId): int => (int) DB::table('wallet_members')->where('wallet_id', $walletId)->where('user_id', $this->ada->id)->max('change_seq');
    $before = [$left->id => $stamps($left->id), $deleted->id => $stamps($deleted->id), $archived->id => $stamps($archived->id)];

    saveProfile($this->adaCredential, profileBody(['display_name' => 'Ada']))->assertOk();

    expect($stamps($left->id))->toBe($before[$left->id])
        ->and($stamps($deleted->id))->toBe($before[$deleted->id])
        ->and($stamps($archived->id))->toBeGreaterThan($before[$archived->id]);
});

test('120 wallets: the first run re-stamps 50 and queues a continuation, the queue drains to all 120 once each', function () {
    $wallets = collect(range(1, 119))->map(function () {
        $wallet = ownedWallet($this->grace->id);
        addMember($wallet->id, $this->ada->id, 'editor');

        return $wallet->id;
    })->push($this->first->id)->push($this->second->id)->unique()->sort()->values();
    $stamps = fn (): array => DB::table('wallet_members')->where('user_id', $this->ada->id)->whereNull('deleted_at')->pluck('change_seq', 'wallet_id')->all();
    $before = $stamps();

    Queue::fake();
    $continuation = app(MembershipService::class)->restampNames($this->ada->id, '');
    $afterFirst = $stamps();
    $moved = fn (array $after): int => count(array_filter($after, fn (int $seq, string $id): bool => $seq > $before[$id], ARRAY_FILTER_USE_BOTH));

    expect($wallets)->toHaveCount(121)
        ->and($continuation)->toBe($wallets[49])
        ->and($moved($afterFirst))->toBe(50);

    $runs = 1;
    while ($continuation !== null) {
        $continuation = app(MembershipService::class)->restampNames($this->ada->id, $continuation);
        $runs++;
    }

    expect($runs)->toBe(3)->and($moved($stamps()))->toBe(121);
});

test('the continuation is dispatched by the job itself', function () {
    foreach (range(1, 50) as $unused) {
        addMember(ownedWallet($this->grace->id)->id, $this->ada->id, 'viewer');
    }

    Queue::fake();
    (new RestampMemberNames($this->ada->id))->handle(app(MembershipService::class));

    Queue::assertPushed(RestampMemberNames::class, 1);
    Queue::assertPushed(RestampMemberNames::class, fn (RestampMemberNames $job): bool => $job->afterWalletId !== '');
});

test('the run starts outside the profile transaction and each unit locks only its wallet', function () {
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'for update')) {
            $statements[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        }
    });

    (new RestampMemberNames($this->ada->id))->handle(app(MembershipService::class));

    expect($statements)->toHaveCount(2)
        ->and(collect($statements)->every(fn (array $s): bool => str_contains($s['sql'], '"wallets"') && ! str_contains($s['sql'], '"users"')))->toBeTrue()
        ->and(collect($statements)->every(fn (array $s): bool => $s['level'] === $this->baseline + 1))->toBeTrue();

    Queue::fake();
    saveProfile($this->adaCredential, profileBody(['display_name' => 'Ada']))->assertOk();
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === ResendMemberNames::class);
});

test('no audit entry, activity entry or domain event is written', function () {
    $domainEvents = [];
    Event::listen('App\\Modules\\*', function (string $name) use (&$domainEvents): void {
        $domainEvents[] = $name;
    });
    $audit = DB::table('audit_entries')->count();
    $activity = WalletActivity::query()->count();

    (new RestampMemberNames($this->ada->id))->handle(app(MembershipService::class));

    expect(DB::table('audit_entries')->count())->toBe($audit)
        ->and(WalletActivity::query()->count())->toBe($activity);
    expect($domainEvents)->toBe([]);
});

test('a run that fails on wallet k leaves the earlier wallets re-stamped and a retry finishes the rest', function () {
    $real = app(MembershipRepository::class);
    $order = [$this->first->id, $this->second->id];
    sort($order);
    $calls = 0;
    $failing = Mockery::mock(MembershipRepository::class);
    $failing->shouldReceive('liveWalletIdsOf')->andReturnUsing(fn (...$a) => $real->liveWalletIdsOf(...$a));
    $failing->shouldReceive('activeFor')->andReturnUsing(fn (...$a) => $real->activeFor(...$a));
    $failing->shouldReceive('restamp')->andReturnUsing(function (...$a) use ($real, &$calls): void {
        if (++$calls === 2) {
            throw new RuntimeException('boom');
        }
        $real->restamp(...$a);
    });
    $service = new MembershipService($failing, app(WalletScopeWrites::class), app(UserScopeSequence::class), app(AuditLog::class), app(ActivityRecorder::class));
    $before = [$order[0] => storedMembership($order[0], $this->ada->id)['change_seq'], $order[1] => storedMembership($order[1], $this->ada->id)['change_seq']];

    expect(fn () => $service->restampNames($this->ada->id, ''))->toThrow(RuntimeException::class, 'boom');
    expect(storedMembership($order[0], $this->ada->id)['change_seq'])->toBeGreaterThan($before[$order[0]])
        ->and(storedMembership($order[1], $this->ada->id)['change_seq'])->toBe($before[$order[1]]);

    $service->restampNames($this->ada->id, '');

    expect(storedMembership($order[1], $this->ada->id)['change_seq'])->toBeGreaterThan($before[$order[1]]);
});

test('a profile item in a sync batch triggers the same re-stamp', function () {
    $cursor = readFeedFully($this->graceCredential, $this->first->id)['cursor'];
    $profileId = DB::table('profiles')->where('user_id', $this->ada->id)->value('id');

    postBatch($this->adaCredential, [syncItem('profile', 'upsert', (string) $profileId, profileBody(['display_name' => 'Batch Ada']))])->assertOk();

    expect(memberRowOnFeed($this->graceCredential, $this->first->id, $cursor, $this->ada->id)['display_name'])->toBe('Batch Ada');
});

test('renames are coalesced while a run waits and a rename after the run started queues a new one', function () {
    config(['queue.default' => 'database']);
    $listener = app(ResendMemberNames::class);
    $event = new ProfileUpdated('e', 'now', $this->ada->id, ['display_name']);
    $queued = fn (): int => DB::table('jobs')->count();

    $listener->handle($event);
    $listener->handle($event);

    expect($queued())->toBe(1);

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
    expect($queued())->toBe(0);

    $listener->handle($event);

    expect($queued())->toBe(1);
});

test('a profile change that does not touch the display name queues nothing', function () {
    Queue::fake();

    app(ResendMemberNames::class)->handle(new ProfileUpdated('e', 'now', $this->ada->id, ['timezone', 'locale']));

    Queue::assertNothingPushed();
});
