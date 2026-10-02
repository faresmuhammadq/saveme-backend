<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Wallets\Application\Categories\CategoryService;
use Illuminate\Support\Facades\DB;

/*
 * GET /sync/user/changes (spec 013, US3).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('a full read returns the profile, sessions, own categories and own memberships, never platform or other people\'s', function () {
    $other = person('grace@example.test');
    $mine = ownedCategory($this->user->id, ['name' => 'Mine']);
    ownedCategory($other->id, ['name' => 'Hers']);
    $wallet = ownedWallet($this->user->id);
    $shared = ownedWallet($other->id);
    $membership = addMember($shared->id, $this->user->id, 'viewer');

    $read = readFeedFully($this->credential, 'user');
    $keys = feedKeys($read['rows']);

    expect($keys)->toContain('category:'.$mine->id)
        ->and($keys)->toContain('wallet_member:'.$membership->id)
        ->and(collect($read['rows'])->pluck('type')->unique()->sort()->values()->all())->toBe(['category', 'device_session', 'profile', 'wallet_member'])
        ->and(collect($read['rows'])->where('type', 'category')->pluck('record.name')->all())->toBe(['Mine'])
        ->and(collect($read['rows'])->where('type', 'wallet_member')->pluck('record.member_user_id')->unique()->all())->toBe([$this->user->id]);

    $positions = collect($read['rows'])->map(fn (array $row): int => $row['type'] === 'wallet_member'
        ? (int) DB::table('wallet_members')->where('id', $row['record']['id'])->value('member_change_seq')
        : $row['record']['change_seq'])->all();
    expect($positions)->toBe(collect($positions)->sort()->values()->all());
});

test('reading from sync_cursor returns only later changes, and a record changed twice arrives once in its latest state', function () {
    $category = ownedCategory($this->user->id, ['name' => 'Tea']);
    $first = readFeedFully($this->credential, 'user');

    app(CategoryService::class)->save($this->user->id, $category->id, categoryValues(['name' => 'Green tea']), 1, presentCategory());
    app(CategoryService::class)->save($this->user->id, $category->id, categoryValues(['name' => 'Black tea']), 2, presentCategory());

    $second = readFeedFully($this->credential, 'user', $first['cursor']);

    expect(feedKeys($second['rows']))->toBe(['category:'.$category->id])
        ->and($second['rows'][0]['record']['name'])->toBe('Black tea')
        ->and($second['rows'][0]['record']['version'])->toBe(3);
});

test('device_session.current is true only for the reading session', function () {
    signInAs('ada@example.test', 'correct horse', deviceBody())->assertOk();

    $sessions = collect(readFeedFully($this->credential, 'user')['rows'])->where('type', 'device_session');

    expect($sessions)->toHaveCount(2)
        ->and($sessions->where('record.current', true))->toHaveCount(1);
});

test('an empty page returns the same position and a non-null sync_cursor', function () {
    $read = readFeedFully($this->credential, 'user');
    $again = readFeed($this->credential, 'user', $read['cursor'])->assertOk();

    expect($again->json('data'))->toBe([])
        ->and($again->json('meta.sync_cursor'))->toBe($read['cursor'])
        ->and($again->json('meta.has_more'))->toBeFalse()
        ->and($again->json('meta.next_cursor'))->toBeNull();
});

test('paging with limit visits every row exactly once, in order', function () {
    foreach (range(1, 5) as $n) {
        ownedCategory($this->user->id, ['name' => "C{$n}"]);
    }

    $whole = feedKeys(readFeedFully($this->credential, 'user', limit: 200)['rows']);
    $paged = feedKeys(readFeedFully($this->credential, 'user', limit: 2)['rows']);

    expect($paged)->toBe($whole)->and(array_unique($paged))->toHaveCount(count($paged));
});

test('a session ending moves the session row on the feed', function () {
    $cursor = readFeedFully($this->credential, 'user')['cursor'];
    $other = DeviceSession::factory()->create(['user_id' => $this->user->id]);
    $this->withHeaders(asCredential($this->credential))->deleteJson('/api/v1/auth/sessions/'.$other->id)->assertNoContent();
    $this->flushHeaders();

    $rows = readFeedFully($this->credential, 'user', $cursor)['rows'];

    expect(feedKeys($rows))->toBe(['device_session:'.$other->id])
        ->and($rows[0]['record']['state'])->toBe('revoked');
});
