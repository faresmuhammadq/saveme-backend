<?php

use App\Modules\Sync\Application\TombstonePurgeRun;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * sync:purge-tombstones (spec 013, US4; R-10, R-11; ADR-0008 rule 12).
 */

beforeEach(function () {
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    [$this->grace] = walletCaller('grace@example.test');
});

function purge(): array
{
    return app(TombstonePurgeRun::class)->handle();
}

function horizonOf(string $table, string $id): int
{
    return (int) DB::table($table)->where('id', $id)->value('purged_change_seq');
}

function deleteTransaction(string $userId, Transaction $transaction): void
{
    app(TransactionService::class)->delete($userId, $transaction->wallet_id, $transaction->id, $transaction->version, presentTransaction());
}

test('tombstones older than the retention are removed and younger ones kept, with the horizon at the greatest removed position', function () {
    $wallet = ownedWallet($this->ada->id);
    $old = recordTransaction($this->ada->id, $wallet->id)->transaction;
    deleteTransaction($this->ada->id, Transaction::query()->findOrFail($old->id));
    $oldPosition = (int) Transaction::query()->findOrFail($old->id)->change_seq;

    $this->travel(91)->days();
    $young = recordTransaction($this->ada->id, $wallet->id)->transaction;
    deleteTransaction($this->ada->id, Transaction::query()->findOrFail($young->id));

    $removed = purge();

    expect($removed['transactions'])->toBe(1)
        ->and(Transaction::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($young->id)->exists())->toBeTrue()
        ->and(horizonOf('wallets', $wallet->id))->toBe($oldPosition);
});

test('a transfer tombstone raises both wallets\' horizons: the source by change_seq, the destination by counter_change_seq', function () {
    $source = ownedWallet($this->ada->id);
    $destination = ownedWallet($this->ada->id);
    $transfer = recordTransaction($this->ada->id, $source->id, transferBody($destination->id))->transaction;
    deleteTransaction($this->ada->id, Transaction::query()->findOrFail($transfer->id));
    $row = Transaction::query()->findOrFail($transfer->id);

    $this->travel(91)->days();
    purge();

    expect(Transaction::query()->whereKey($transfer->id)->exists())->toBeFalse()
        ->and(horizonOf('wallets', $source->id))->toBe($row->change_seq)
        ->and(horizonOf('wallets', $destination->id))->toBe($row->counter_change_seq);
});

test('a membership tombstone raises the wallet\'s and the member\'s user horizon; references go too', function () {
    $wallet = ownedWallet($this->ada->id);
    $member = addMember($wallet->id, $this->grace->id, 'viewer');
    $member->forceFill(['deleted_at' => now(), 'version' => 2, 'change_seq' => 40, 'member_change_seq' => 77])->save();
    $reference = CategoryReference::factory()->create(['wallet_id' => $wallet->id, 'category_id' => ownedCategory($this->ada->id)->id, 'deleted_at' => now(), 'change_seq' => 55]);

    $this->travel(91)->days();
    $removed = purge();

    expect($removed['wallet_members'])->toBe(1)
        ->and($removed['category_references'])->toBe(1)
        ->and(WalletMember::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(CategoryReference::query()->whereKey($reference->id)->exists())->toBeFalse()
        ->and(horizonOf('wallets', $wallet->id))->toBe(55)
        ->and(horizonOf('users', $this->grace->id))->toBe(77);
});

test('a tombstoned wallet a live transfer still posts to is kept, and removed on a later run once unreferenced', function () {
    $live = ownedWallet($this->ada->id);
    $doomed = ownedWallet($this->ada->id);
    $transfer = recordTransaction($this->ada->id, $live->id, transferBody($doomed->id))->transaction;
    walletCall('DELETE', $this->adaCredential, '/wallets/'.$doomed->id, ['version' => 1])->assertOk();

    $this->travel(91)->days();
    $first = purge();

    expect($first['wallets'])->toBe(0)
        ->and(Wallet::query()->whereKey($doomed->id)->exists())->toBeTrue()
        ->and(WalletMember::query()->where('wallet_id', $doomed->id)->exists())->toBeFalse();

    Posting::query()->where('transaction_id', $transfer->id)->delete();
    Transaction::query()->whereKey($transfer->id)->update(['deleted_at' => now()->subDays(100)]);
    $second = purge();

    expect($second['wallets'])->toBe(1)->and(Wallet::query()->whereKey($doomed->id)->exists())->toBeFalse();
});

test('a person\'s tombstoned category is purged only once nothing refers to it, raising the owner\'s horizon', function () {
    $wallet = ownedWallet($this->ada->id);
    $free = ownedCategory($this->ada->id, ['name' => 'Free', 'deleted_at' => now(), 'version' => 2, 'change_seq' => 31]);
    $used = ownedCategory($this->ada->id, ['name' => 'Used', 'deleted_at' => now(), 'version' => 2, 'change_seq' => 32]);
    recordTransaction($this->ada->id, $wallet->id, transactionBody(['category_id' => platformCategoryId()]));
    Transaction::query()->update(['category_id' => $used->id]);

    $this->travel(91)->days();
    $removed = purge();

    expect($removed['categories'])->toBe(1)
        ->and(Category::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(Category::query()->whereKey($used->id)->exists())->toBeTrue()
        ->and(horizonOf('users', $this->ada->id))->toBe(31);
});

test('a second run removes nothing, and a retention under 90 days is clamped to 90 with a warning', function () {
    $wallet = ownedWallet($this->ada->id);
    $t = recordTransaction($this->ada->id, $wallet->id)->transaction;
    deleteTransaction($this->ada->id, Transaction::query()->findOrFail($t->id));
    config(['sync.tombstone_retention_days' => 30]);

    $this->travel(40)->days();
    Log::spy();
    $early = purge();
    expect($early['transactions'])->toBe(0);
    Log::shouldHaveReceived('warning')->with('sync_retention_clamped', Mockery::any())->once();

    $this->travel(60)->days();
    $first = purge();
    $second = purge();

    expect($first['transactions'])->toBe(1)->and(array_sum($second))->toBe(0);
});

test('the run logs one summary line without identifiers or content', function () {
    Log::spy();
    purge();

    Log::shouldHaveReceived('info')->with('sync_tombstones_purged', Mockery::on(fn (array $context): bool => array_keys($context) === ['retention_days', 'removed']))->once();
});

test('the command runs the purge', function () {
    $this->artisan('sync:purge-tombstones')->assertSuccessful();
});
