<?php

use App\Modules\Sync\Application\TombstonePurgeRun;
use App\Modules\Sync\Domain\Ports\CursorSigner;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\DB;

/*
 * A cursor behind a purge horizon is expired, for that scope only (spec 013,
 * US4; R-10).
 */

beforeEach(function () {
    [$this->ada, $this->credential] = walletCaller('ada@example.test');
    $this->wallet = ownedWallet($this->ada->id);
    $this->other = ownedWallet($this->ada->id);
    $this->tombstone = recordTransaction($this->ada->id, $this->wallet->id)->transaction;
});

/**
 * Travelling past the idle limit would end the session; keep it in use.
 */
function keepSessionAlive(): void
{
    DB::table('device_sessions')->update(['last_used_at' => now()]);
}

function deleteAndAge(string $userId, Transaction $transaction): void
{
    app(TransactionService::class)->delete($userId, $transaction->wallet_id, $transaction->id, $transaction->version, presentTransaction());
}

test('a cursor behind a purged position expires that wallet\'s feed only', function () {
    $walletCursor = readFeedFully($this->credential, $this->wallet->id)['cursor'];
    $otherCursor = readFeedFully($this->credential, $this->other->id)['cursor'];
    $userCursor = readFeedFully($this->credential, 'user')['cursor'];

    deleteAndAge($this->ada->id, Transaction::query()->findOrFail($this->tombstone->id));
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();

    readFeed($this->credential, $this->wallet->id, $walletCursor)->assertStatus(410)->assertJsonPath('code', 'SYNC_CURSOR_EXPIRED');
    readFeed($this->credential, $this->other->id, $otherCursor)->assertOk();
    readFeed($this->credential, 'user', $userCursor)->assertOk();
});

test('a cursor at or after the horizon is served however old, and an absent cursor is never expired', function () {
    deleteAndAge($this->ada->id, Transaction::query()->findOrFail($this->tombstone->id));
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();

    $fresh = readFeedFully($this->credential, $this->wallet->id);
    $this->travel(400)->days();
    keepSessionAlive();

    readFeed($this->credential, $this->wallet->id, $fresh['cursor'])->assertOk();
    $full = readFeedFully($this->credential, $this->wallet->id);
    expect(feedKeys($full['rows']))->not->toContain('transaction:'.$this->tombstone->id);
});

test('a purged wallet\'s feed is 404', function () {
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();

    readFeed($this->credential, $this->wallet->id)->assertNotFound();
});

test('a user feed cursor behind a purged membership expires, a fresh read does not', function () {
    $userCursor = readFeedFully($this->credential, 'user')['cursor'];
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();

    readFeed($this->credential, 'user', $userCursor)->assertStatus(410);
    readFeed($this->credential, 'user')->assertOk();
});

/**
 * Reads a feed from the start a page at a time and returns every row served.
 *
 * @return list<array{type: string, record: array<string, mixed>}>
 */
function pageToTheEnd(string $credential, string $feed, int $limit): array
{
    $rows = [];
    $cursor = null;
    $pages = 0;

    do {
        $page = readFeed($credential, $feed, $cursor, $limit)->assertOk();
        $rows = [...$rows, ...$page->json('data')];
        $cursor = $page->json('meta.next_cursor');
        $pages++;
    } while ($page->json('meta.has_more') && $pages < 100);

    return $rows;
}

test('a wallet feed read from the start pages through a purge horizon at a small limit', function () {
    foreach (range(1, 7) as $unused) {
        recordTransaction($this->ada->id, $this->wallet->id);
    }
    // The purged tombstone is the highest position, so the horizon is above every page but the last.
    deleteAndAge($this->ada->id, Transaction::query()->findOrFail($this->tombstone->id));
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();
    $horizon = (int) DB::table('wallets')->where('id', $this->wallet->id)->value('purged_change_seq');

    $rows = pageToTheEnd($this->credential, $this->wallet->id, 3);

    expect($horizon)->toBeGreaterThan(6)
        ->and(collect($rows)->where('type', 'transaction'))->toHaveCount(7)
        ->and(feedKeys($rows))->not->toContain('transaction:'.$this->tombstone->id);
});

test('a user feed read from the start pages through a purge horizon at limit 1', function () {
    foreach (range(1, 4) as $unused) {
        ownedCategory($this->ada->id);
    }
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();
    $horizon = (int) DB::table('users')->where('id', $this->ada->id)->value('purged_change_seq');

    $rows = pageToTheEnd($this->credential, 'user', 1);

    expect($horizon)->toBeGreaterThan(1)
        ->and(collect($rows)->where('type', 'category'))->toHaveCount(4);
});

test('a cursor issued before a later purge still expires, even if its read began after an earlier one', function () {
    foreach (range(1, 4) as $unused) {
        recordTransaction($this->ada->id, $this->wallet->id);
    }
    deleteAndAge($this->ada->id, Transaction::query()->findOrFail($this->tombstone->id));
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();

    $first = readFeed($this->credential, $this->wallet->id, null, 2)->assertOk();
    expect($first->json('meta.has_more'))->toBeTrue();
    $cursor = $first->json('meta.next_cursor');

    $extra = recordTransaction($this->ada->id, $this->wallet->id)->transaction;
    deleteAndAge($this->ada->id, Transaction::query()->findOrFail($extra->id));
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();

    readFeed($this->credential, $this->wallet->id, $cursor)->assertStatus(410)->assertJsonPath('code', 'SYNC_CURSOR_EXPIRED');
});

test('a cursor without the starting horizon keeps the rule before spec 017', function () {
    $legacy = fn (int $position): string => (function () use ($position): string {
        $signer = app(CursorSigner::class);
        $payload = (string) json_encode(['v' => 1, 'f' => 'w', 's' => $this->wallet->id, 'r' => $this->ada->id, 'p' => $position]);

        return rtrim(strtr(base64_encode($payload.substr($signer->sign($payload), 0, 16)), '+/', '-_'), '=');
    })();
    foreach (range(1, 4) as $unused) {
        recordTransaction($this->ada->id, $this->wallet->id);
    }
    deleteAndAge($this->ada->id, Transaction::query()->findOrFail($this->tombstone->id));
    $this->travel(91)->days();
    app(TombstonePurgeRun::class)->handle();
    keepSessionAlive();
    $horizon = (int) DB::table('wallets')->where('id', $this->wallet->id)->value('purged_change_seq');

    readFeed($this->credential, $this->wallet->id, $legacy($horizon - 1))->assertStatus(410);
    readFeed($this->credential, $this->wallet->id, $legacy($horizon))->assertOk();
});
