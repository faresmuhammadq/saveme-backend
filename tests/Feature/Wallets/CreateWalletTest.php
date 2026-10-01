<?php

use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;
use App\Modules\Wallets\Application\Dto\SavedWallet;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Events\WalletCreated;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Creating a wallet (spec 010, US1; FR-001, FR-013, FR-014).
 */

function createWallet(string $userId, ?string $walletId = null, array $changes = []): SavedWallet
{
    return app(WalletService::class)->save($userId, $walletId ?? (string) Str::uuid7(), walletValues($changes), 0, presentWallet());
}

test('the creator is the only member, with the owner role', function () {
    $user = person();

    $saved = createWallet($user->id);

    $members = WalletMember::query()->where('wallet_id', $saved->view->wallet->id)->get();
    expect($saved->created)->toBeTrue()
        ->and($members)->toHaveCount(1)
        ->and($members[0]->user_id)->toBe($user->id)
        ->and($members[0]->role)->toBe('owner')
        ->and($saved->view->myRole->value)->toBe('owner');
});

test('the wallet and its owner commit together or not at all', function () {
    $user = person();
    $real = app(ScopeSequence::class);
    $calls = 0;
    app()->bind(ScopeSequence::class, fn () => new class($real, $calls) implements ScopeSequence
    {
        public function __construct(private ScopeSequence $real, private int &$calls) {}

        public function nextFor(string $walletId): int
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('boom');
            }

            return $this->real->nextFor($walletId);
        }
    });

    expect(fn () => createWallet($user->id))->toThrow(RuntimeException::class);

    expect(Wallet::query()->count())->toBe(0)
        ->and(WalletMember::query()->count())->toBe(0);
});

test('a new wallet starts at version 1 with the balance equal to the opening balance', function () {
    $user = person();

    $wallet = createWallet($user->id, changes: ['opening_balance_minor' => -120000, 'type' => 'credit_card'])->view->wallet;

    expect($wallet->version)->toBe(1)
        ->and($wallet->balanceMinor)->toBe(-120000)
        ->and($wallet->openingBalanceMinor)->toBe(-120000)
        ->and($wallet->archivedAt)->toBeNull();
});

test('replaying a create creates nothing and bumps nothing', function () {
    $user = person();
    $id = (string) Str::uuid7();

    $first = createWallet($user->id, $id)->view->wallet;
    $again = createWallet($user->id, $id);

    expect($again->created)->toBeFalse()
        ->and($again->view->wallet->version)->toBe($first->version)
        ->and($again->view->wallet->changeSeq)->toBe($first->changeSeq)
        ->and(Wallet::query()->count())->toBe(1)
        ->and(WalletMember::query()->count())->toBe(1);
});

test('wallets.max is decided by the entitlement service, counting archived and not deleted wallets', function () {
    $plan = Plan::query()->where('is_default', true)->firstOrFail();
    $plan->features = [...$plan->features, 'wallets.max' => 1];
    $plan->save();
    $user = person();

    $first = createWallet($user->id)->view->wallet;
    expect(fn () => createWallet($user->id))->toThrow(function (Refusal $refusal): void {
        expect($refusal->errorCode)->toBe(ErrorCode::FeatureNotEntitled);
    });

    Wallet::query()->whereKey($first->id)->update(['archived_at' => now()]);
    expect(fn () => createWallet($user->id))->toThrow(FeatureNotEntitled::class);

    Wallet::query()->whereKey($first->id)->update(['deleted_at' => now()]);
    expect(createWallet($user->id)->created)->toBeTrue();
});

test('WalletCreated follows the commit with identifiers and scalars', function () {
    Event::fake([WalletCreated::class]);
    $user = person();

    $wallet = createWallet($user->id, changes: ['type' => 'savings', 'currency' => 'USD'])->view->wallet;

    Event::assertDispatched(WalletCreated::class, fn (WalletCreated $event): bool => $event->walletId === $wallet->id
        && $event->ownerUserId === $user->id
        && $event->type === 'savings'
        && $event->currency === 'USD');
});
