<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Items are independent and the batch holds no outer transaction (spec 013,
 * US2, A-10, FR-016a).
 */

/**
 * Binds a posting stand-in that runs the callback whenever a wallet is created.
 */
function onWalletCreate(Closure $callback): void
{
    app()->bind(PostingTotals::class, fn () => new class($callback) implements PostingTotals
    {
        public function __construct(private Closure $callback) {}

        public function sumFor(string $walletId): int
        {
            ($this->callback)($walletId);

            return 0;
        }

        public function hasAny(string $walletId): bool
        {
            return false;
        }

        public function lockedAmong(array $walletIds): array
        {
            return [];
        }
    });
}

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('an item whose write throws is rejected with 500 INTERNAL_ERROR; earlier items stay committed and later ones run', function () {
    $ids = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    onWalletCreate(fn () => throw new RuntimeException('secret stack detail'));

    $response = postBatch($this->credential, [
        syncItem('category', 'upsert', $ids[0], categoryBody()),
        syncItem('wallet', 'upsert', $ids[1], walletBody()),
        syncItem('category', 'upsert', $ids[2], categoryBody(['name' => 'Tea'])),
    ])->assertOk();

    expect($response->json('data.*.outcome'))->toBe(['applied', 'rejected', 'applied'])
        ->and($response->json('data.1.status'))->toBe(500)
        ->and($response->json('data.1.problem.code'))->toBe('INTERNAL_ERROR')
        ->and($response->getContent())->not->toContain('secret stack detail')
        ->and(Category::query()->whereKey([$ids[0], $ids[2]])->count())->toBe(2)
        ->and(Wallet::query()->whereKey($ids[1])->exists())->toBeFalse();
});

test('the batch holds no outer transaction: an item runs at the same depth as the single write', function () {
    $depths = [];
    onWalletCreate(function () use (&$depths): void {
        $depths[] = DB::transactionLevel();
    });

    walletCall('PUT', $this->credential, '/wallets/'.(string) Str::uuid7(), walletBody())->assertCreated();
    postBatch($this->credential, [
        syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody()),
        syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody(['name' => 'Second'])),
    ])->assertOk();

    expect($depths)->toHaveCount(3)->and(array_unique($depths))->toHaveCount(1);
});

test('revoking the caller\'s session after the first item does not stop the remaining items', function () {
    $second = (string) Str::uuid7();
    onWalletCreate(fn () => DeviceSession::query()->where('user_id', $this->user->id)->update(['state' => 'revoked', 'end_reason' => 'ended_by_owner', 'ended_at' => now()]));

    $response = postBatch($this->credential, [
        syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody()),
        syncItem('category', 'upsert', $second, categoryBody()),
    ])->assertOk();

    expect($response->json('data.*.outcome'))->toBe(['applied', 'applied'])
        ->and(Category::query()->whereKey($second)->exists())->toBeTrue();
});
