<?php

use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Wallets\Application\Departure\LeaveRemainingWallets;
use Illuminate\Support\Facades\DB;

/*
 * Wallets' leaving step of an account purge (spec 022, FR-044): every
 * remaining membership ends as leaving ends it.
 */

beforeEach(function () {
    $this->d = person();
    $this->p = person('pat@example.test');
    $this->walletA = ownedWallet($this->p->id);
    $this->walletB = ownedWallet($this->p->id);
    addMember($this->walletA->id, $this->d->id, 'editor');
    addMember($this->walletB->id, $this->d->id, 'viewer');
});

test('each remaining membership is tombstoned like leaving does, counted, and a second call is a no-op', function () {
    $step = app(LeaveRemainingWallets::class);

    $counts = $step->purge($this->d->id, purgeContextFor($this->d->id));

    expect($step->step())->toBe(PurgeStep::LeaveWallets)
        ->and($counts->get('memberships_ended'))->toBe(2)
        ->and(DB::table('wallet_members')->where('user_id', $this->d->id)->whereNull('deleted_at')->count())->toBe(0)
        ->and(DB::table('audit_entries')->where('event', 'membership_revoked')->where('actor_id', $this->d->id)->count())->toBe(2)
        ->and(DB::table('wallet_activity')->where('action', 'left')->where('actor_user_id', $this->d->id)->count())->toBe(2);

    $stamps = DB::table('wallet_members')->where('user_id', $this->d->id)->pluck('version', 'id')->all();
    expect($step->purge($this->d->id, purgeContextFor($this->d->id))->get('memberships_ended'))->toBe(0)
        ->and(DB::table('wallet_members')->where('user_id', $this->d->id)->pluck('version', 'id')->all())->toBe($stamps)
        ->and(DB::table('audit_entries')->where('event', 'membership_revoked')->count())->toBe(2);
});

test('the other members and their entries are untouched', function () {
    recordTransaction($this->d->id, $this->walletA->id);

    app(LeaveRemainingWallets::class)->purge($this->d->id, purgeContextFor($this->d->id));

    expect(DB::table('wallet_members')->where('user_id', $this->p->id)->whereNull('deleted_at')->count())->toBe(2)
        ->and(DB::table('transactions')->where('wallet_id', $this->walletA->id)->whereNull('deleted_at')->count())->toBe(1);
});
