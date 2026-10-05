<?php

use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Identity\Domain\Deletion\Successor;
use App\Modules\Identity\Domain\Deletion\SuccessorReason;
use App\Modules\Wallets\Application\Departure\ResolveOwnedWallets;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * Wallets' ownership step of an account purge (spec 022, FR-030 to FR-032,
 * FR-045): transfer where someone else belongs, delete where nobody does.
 */

beforeEach(function () {
    Mail::fake();
    $this->d = person();
    $this->p = person('pat@example.test');
});

test('it transfers shared wallets and deletes solo ones, counts them, and a second call is a no-op', function () {
    $shared = ownedWallet($this->d->id);
    addMember($shared->id, $this->p->id, 'viewer');
    $solo = ownedWallet($this->d->id);
    $step = app(ResolveOwnedWallets::class);

    $counts = $step->purge($this->d->id, purgeContextFor($this->d->id));

    expect($step->step())->toBe(PurgeStep::ResolveOwnedWallets)
        ->and($counts->get('wallets_transferred'))->toBe(1)
        ->and($counts->get('wallets_deleted'))->toBe(1)
        ->and(DB::table('wallet_members')->where('wallet_id', $shared->id)->where('role', 'owner')->value('user_id'))->toBe($this->p->id)
        ->and(Wallet::query()->find($solo->id)->deleted_at)->not->toBeNull();

    $versions = Wallet::query()->whereIn('id', [$shared->id, $solo->id])->pluck('version', 'id')->all();
    $again = $step->purge($this->d->id, purgeContextFor($this->d->id));

    expect($again->get('wallets_transferred'))->toBe(0)->and($again->get('wallets_deleted'))->toBe(0)
        ->and(Wallet::query()->whereIn('id', [$shared->id, $solo->id])->pluck('version', 'id')->all())->toBe($versions);
});

test('wallets are handled in ascending id, each in its own unit of work', function () {
    $wallets = collect(range(1, 3))->map(function () {
        $wallet = ownedWallet($this->d->id);
        addMember($wallet->id, $this->p->id, 'editor');

        return $wallet->id;
    })->sort()->values();
    $order = [];
    Event::listen(WalletOwnershipTransferred::class, function ($event) use (&$order): void {
        $order[] = $event->walletId;
    });

    app(ResolveOwnedWallets::class)->purge($this->d->id, purgeContextFor($this->d->id));

    expect($order)->toBe($wallets->all());
});

test('a wallet whose members changed between listing and acting is judged again', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'editor');
    $q = person('quinn@example.test');
    addMember($wallet->id, $q->id, 'viewer');
    $stale = (new Successor($this->p->id, SuccessorReason::OldestEditor));
    expect($stale->userId)->toBe($this->p->id);

    DB::table('wallet_members')->where('wallet_id', $wallet->id)->where('user_id', $this->p->id)->update(['deleted_at' => now()]);

    app(ResolveOwnedWallets::class)->purge($this->d->id, purgeContextFor($this->d->id));

    expect(DB::table('wallet_members')->where('wallet_id', $wallet->id)->where('role', 'owner')->value('user_id'))->toBe($q->id);
});
