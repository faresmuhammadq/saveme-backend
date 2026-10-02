<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Category references: publish, republish, withdraw and cascade (spec 011,
 * US4; FR-021 to FR-025, R-8).
 */

/**
 * Every change_seq in a wallet's scope: the wallet's own, its members' and its references'.
 *
 * @return array{wallet: int, others: list<int>}
 */
function scopeOf(string $walletId): array
{
    return [
        'wallet' => (int) Wallet::query()->whereKey($walletId)->value('change_seq'),
        'others' => [
            ...WalletMember::query()->where('wallet_id', $walletId)->pluck('change_seq')->all(),
            ...CategoryReference::query()->where('wallet_id', $walletId)->pluck('change_seq')->all(),
        ],
    ];
}

function expectWalletScopeIntact(string $walletId): void
{
    $scope = scopeOf($walletId);

    expect(array_unique($scope['others']))->toHaveCount(count($scope['others']))
        ->and($scope['wallet'])->toBeGreaterThan(max($scope['others']));
}

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->wallet = ownedWallet($this->partner);
    addMember($this->wallet->id, $this->owner, 'editor');
    $this->category = ownedCategory($this->owner, ['name' => 'Coffee', 'icon' => 'cup', 'color' => '#6D4C41']);
    $this->references = app(CategoryReferences::class);
});

test('publish stores one reference with the display fields, a wallet change_seq, and the wallet last', function () {
    $versionBefore = Wallet::query()->findOrFail($this->wallet->id)->version;

    $this->references->publish($this->wallet->id, $this->category->id);

    $reference = CategoryReference::query()->where('wallet_id', $this->wallet->id)->sole();
    expect($reference->category_id)->toBe($this->category->id)
        ->and($reference->owner_user_id)->toBe($this->owner)
        ->and($reference->kind)->toBe('expense')
        ->and($reference->name)->toBe('Coffee')
        ->and($reference->icon)->toBe('cup')
        ->and($reference->color)->toBe('#6D4C41')
        ->and($reference->version)->toBe(1)
        ->and($reference->deleted_at)->toBeNull()
        ->and(Wallet::query()->findOrFail($this->wallet->id)->version)->toBe($versionBefore);
    expectWalletScopeIntact($this->wallet->id);
});

test('publishing again is a no-op', function () {
    $this->references->publish($this->wallet->id, $this->category->id);
    $scope = scopeOf($this->wallet->id);

    $this->references->publish($this->wallet->id, $this->category->id);

    expect(CategoryReference::query()->count())->toBe(1)
        ->and(scopeOf($this->wallet->id))->toBe($scope);
});

test('a platform category never gets a reference', function () {
    $platform = Category::query()->whereNull('user_id')->firstOrFail();

    $this->references->publish($this->wallet->id, $platform->id);

    expect(CategoryReference::query()->count())->toBe(0);
});

test('at most one live reference per wallet and category, enforced by the database', function () {
    $this->references->publish($this->wallet->id, $this->category->id);
    $existing = CategoryReference::query()->sole();

    expect(fn () => DB::transaction(fn () => CategoryReference::factory()->create([
        'wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'owner_user_id' => $this->owner, 'name' => 'Coffee',
    ])))->toThrow(QueryException::class);

    expect($existing->fresh()->deleted_at)->toBeNull();
});

test('withdrawIfUnused tombstones, and publishing again revives the same row as a new version', function () {
    $this->references->publish($this->wallet->id, $this->category->id);
    $first = CategoryReference::query()->sole();

    $this->references->withdrawIfUnused($this->wallet->id, $this->category->id);
    $withdrawn = CategoryReference::query()->sole();
    expect($withdrawn->deleted_at)->not->toBeNull()->and($withdrawn->version)->toBe(2);
    expectWalletScopeIntact($this->wallet->id);

    $this->references->publish($this->wallet->id, $this->category->id);
    $revived = CategoryReference::query()->sole();
    expect($revived->id)->toBe($first->id)
        ->and($revived->deleted_at)->toBeNull()
        ->and($revived->version)->toBe(3)
        ->and($revived->change_seq)->toBeGreaterThan($withdrawn->change_seq);
    expectWalletScopeIntact($this->wallet->id);
});

test('renaming, or changing the icon or colour, updates every live reference in the same transaction', function (array $change) {
    $second = ownedWallet($this->partner);
    $this->references->publish($this->wallet->id, $this->category->id);
    $this->references->publish($second->id, $this->category->id);
    $before = CategoryReference::query()->get()->keyBy('wallet_id');

    app(CategoryService::class)->patch($this->owner, $this->category->id, $change, 1, presentCategory());

    foreach ([$this->wallet->id, $second->id] as $walletId) {
        $after = CategoryReference::query()->where('wallet_id', $walletId)->sole();
        expect($after->version)->toBe($before[$walletId]->version + 1)
            ->and($after->change_seq)->toBeGreaterThan($before[$walletId]->change_seq)
            ->and($after->only(array_keys($change)))->toBe($change);
        expectWalletScopeIntact($walletId);
    }
})->with([
    'name' => [['name' => 'Espresso']],
    'icon' => [['icon' => 'mug']],
    'color' => [['color' => '#000000']],
]);

test('a failed save rolls the republication back with it', function () {
    $this->references->publish($this->wallet->id, $this->category->id);
    $before = CategoryReference::query()->sole();

    try {
        DB::transaction(function (): void {
            app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());

            throw new RuntimeException('the unit of work failed');
        });
    } catch (RuntimeException) {
    }

    expect(CategoryReference::query()->sole()->name)->toBe($before->name)
        ->and(CategoryReference::query()->sole()->version)->toBe($before->version)
        ->and(Category::query()->findOrFail($this->category->id)->name)->toBe('Coffee');
});

test('archiving and an identical replay change no reference', function () {
    $this->references->publish($this->wallet->id, $this->category->id);
    $before = CategoryReference::query()->sole();
    $scope = scopeOf($this->wallet->id);

    app(CategoryService::class)->save($this->owner, $this->category->id, categoryValues(['name' => 'Coffee']), 1, presentCategory());
    app(CategoryService::class)->archive($this->owner, $this->category->id, 1, presentCategory());

    expect(CategoryReference::query()->sole()->version)->toBe($before->version)
        ->and(scopeOf($this->wallet->id))->toBe($scope);
});

test('deleting the category tombstones its references', function () {
    $this->references->publish($this->wallet->id, $this->category->id);

    app(CategoryService::class)->delete($this->owner, $this->category->id, 1, presentCategory());

    expect(CategoryReference::query()->sole()->deleted_at)->not->toBeNull();
    expectWalletScopeIntact($this->wallet->id);
});

test('deleting a wallet tombstones its references and leaves the category untouched', function () {
    $this->references->publish($this->wallet->id, $this->category->id);
    $categoryBefore = Category::query()->findOrFail($this->category->id);

    app(WalletService::class)->delete($this->partner, $this->wallet->id, 1, presentWallet());

    $reference = CategoryReference::query()->sole();
    expect($reference->deleted_at)->not->toBeNull()
        ->and($reference->version)->toBe(2)
        ->and(Category::query()->findOrFail($this->category->id)->version)->toBe($categoryBefore->version)
        ->and(Category::query()->findOrFail($this->category->id)->deleted_at)->toBeNull();
    expectWalletScopeIntact($this->wallet->id);
});

test('republish locks the wallets in ascending id order', function () {
    $ids = collect(range(1, 3))->map(fn () => (string) Str::uuid7())->sortDesc()->values();
    foreach ($ids as $id) {
        Wallet::factory()->ownedBy($this->partner)->create(['id' => $id]);
        $this->references->publish($id, $this->category->id);
    }

    $locked = [];
    $real = app(WalletRepository::class);
    app()->instance(WalletRepository::class, new class($real, $locked) implements WalletRepository
    {
        public function __construct(private WalletRepository $inner, public array &$locked) {}

        public function find(string $walletId): ?WalletRecord
        {
            return $this->inner->find($walletId);
        }

        public function findForUpdate(string $walletId): ?WalletRecord
        {
            $this->locked[] = $walletId;

            return $this->inner->findForUpdate($walletId);
        }

        public function insert(string $walletId, array $values, int $postingsTotal): void
        {
            $this->inner->insert($walletId, $values, $postingsTotal);
        }

        public function updateIfVersion(string $walletId, int $expectedVersion, array $columns): bool
        {
            return $this->inner->updateIfVersion($walletId, $expectedVersion, $columns);
        }

        public function refreshBalance(string $walletId, int $postingsTotal): bool
        {
            return $this->inner->refreshBalance($walletId, $postingsTotal);
        }

        public function listForMember(string $userId, string $sort, bool $descending, ?array $after, int $limit): array
        {
            return $this->inner->listForMember($userId, $sort, $descending, $after, $limit);
        }

        public function sortValue(WalletRecord $wallet, string $sort): string
        {
            return $this->inner->sortValue($wallet, $sort);
        }

        public function countOwnedLive(string $userId): int
        {
            return $this->inner->countOwnedLive($userId);
        }

        public function liveIds(): array
        {
            return $this->inner->liveIds();
        }
    });

    app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());

    $sorted = $ids->sort()->values()->all();
    expect($locked)->toBe($sorted);
});
