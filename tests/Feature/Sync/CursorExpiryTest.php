<?php

use App\Modules\Sync\Application\TombstonePurgeRun;
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
