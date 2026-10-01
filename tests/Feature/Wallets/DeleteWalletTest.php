<?php

use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\WalletQueryService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Events\WalletDeleted;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Deleting a wallet (spec 010, US3; FR-010, P-07 rule 5, R-9).
 */

beforeEach(function () {
    $this->owner = person();
    $this->editor = person('grace@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    addMember($this->wallet->id, $this->editor->id, 'editor');
});

function deleteWallet(string $userId, string $walletId, int $version): WalletView
{
    return app(WalletService::class)->delete($userId, $walletId, $version, presentWallet());
}

test('deleting tombstones the wallet and every membership, never removing rows', function () {
    $before = WalletMember::query()->where('wallet_id', $this->wallet->id)->get()->keyBy('id');
    $versionBefore = $this->wallet->fresh()->version;

    $view = deleteWallet($this->owner->id, $this->wallet->id, $versionBefore);

    $wallet = Wallet::query()->find($this->wallet->id);
    expect($view->wallet->deletedAt)->not->toBeNull()
        ->and($wallet->deleted_at)->not->toBeNull()
        ->and($wallet->version)->toBe($versionBefore + 1);

    $members = WalletMember::query()->where('wallet_id', $this->wallet->id)->get();
    expect($members)->toHaveCount(2);
    foreach ($members as $member) {
        expect($member->deleted_at)->not->toBeNull()
            ->and($member->version)->toBe($before[$member->id]->version + 1)
            ->and($member->change_seq)->toBeGreaterThan($before[$member->id]->change_seq);
    }
    expect($members->pluck('change_seq')->unique())->toHaveCount(2)
        ->and($wallet->change_seq)->toBeGreaterThan($members->max('change_seq'));
});

test('a registered participant runs inside the same transaction and its failure rolls everything back', function () {
    $participant = new class implements WalletDeletionParticipant
    {
        public array $seen = [];

        public bool $fail = false;

        public function tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void
        {
            $this->seen[] = [$walletId, DB::transactionLevel() > 0, $nextChangeSeq()];

            if ($this->fail) {
                throw new RuntimeException('participant failed');
            }
        }
    };
    app()->instance('test.participant', $participant);
    app()->tag(['test.participant'], WalletDeletionParticipant::class);
    $version = $this->wallet->fresh()->version;

    $participant->fail = true;
    expect(fn () => deleteWallet($this->owner->id, $this->wallet->id, $version))->toThrow(RuntimeException::class);
    expect(Wallet::query()->find($this->wallet->id)->deleted_at)->toBeNull()
        ->and(WalletMember::query()->where('wallet_id', $this->wallet->id)->whereNotNull('deleted_at')->count())->toBe(0);

    $participant->fail = false;
    deleteWallet($this->owner->id, $this->wallet->id, $version);
    expect(Wallet::query()->find($this->wallet->id)->deleted_at)->not->toBeNull()
        ->and($participant->seen[1][0])->toBe($this->wallet->id);
});

test('a repeat delete changes nothing', function () {
    $version = $this->wallet->fresh()->version;
    deleteWallet($this->owner->id, $this->wallet->id, $version);
    $after = Wallet::query()->find($this->wallet->id);

    $again = deleteWallet($this->owner->id, $this->wallet->id, 999);

    $stored = Wallet::query()->find($this->wallet->id);
    expect($again->wallet->deletedAt)->not->toBeNull()
        ->and($stored->version)->toBe($after->version)
        ->and($stored->change_seq)->toBe($after->change_seq);
});

test('WalletDeleted follows the commit', function () {
    Event::fake([WalletDeleted::class]);

    deleteWallet($this->owner->id, $this->wallet->id, $this->wallet->fresh()->version);

    Event::assertDispatched(WalletDeleted::class, fn (WalletDeleted $event): bool => $event->walletId === $this->wallet->id && $event->actorUserId === $this->owner->id);
});

test('the wallet leaves every member\'s list; a former member reads the tombstone, a stranger is refused', function () {
    $stranger = person('stranger@example.test');
    deleteWallet($this->owner->id, $this->wallet->id, $this->wallet->fresh()->version);

    $queries = app(WalletQueryService::class);
    expect($queries->listFor($this->owner->id, 'created_at', false, null, 10)['items'])->toBe([])
        ->and($queries->listFor($this->editor->id, 'created_at', false, null, 10)['items'])->toBe([])
        ->and($queries->find($this->editor->id, $this->wallet->id)->wallet->deletedAt)->not->toBeNull()
        ->and($queries->find($this->editor->id, $this->wallet->id)->myRole->value)->toBe('editor');

    expect(fn () => $queries->find($stranger->id, $this->wallet->id))->toThrow(WalletNotFound::class);
});
