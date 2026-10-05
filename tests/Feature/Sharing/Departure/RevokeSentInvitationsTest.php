<?php

use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Sharing\Application\Departure\RevokeSentInvitations;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * Sharing's step of an account purge (spec 022, FR-043): the invitations the
 * departing person sent are revoked, their addresses erased.
 */

beforeEach(function () {
    Mail::fake();
    $this->d = person();
    $this->other = person('other@example.test');
    $this->wallet = ownedWallet($this->d->id);
});

test('every pending invitation the person sent is revoked, its address erased; the count is returned; a second call is a no-op', function () {
    $mine = pendingInvitation($this->d->id, $this->wallet->id, 'one@example.test');
    $link = pendingInvitation($this->d->id, $this->wallet->id, null);
    $others = ownedWallet($this->other->id);
    $notMine = pendingInvitation($this->other->id, $others->id, 'theirs@example.test');
    $step = app(RevokeSentInvitations::class);

    $counts = $step->purge($this->d->id, purgeContextFor($this->d->id));

    expect($step->step())->toBe(PurgeStep::RevokeInvitations)
        ->and($counts->get('invitations_revoked'))->toBe(2)
        ->and(DB::table('invitations')->whereIn('id', [$mine['id'], $link['id']])->pluck('status')->unique()->all())->toBe(['revoked'])
        ->and(DB::table('invitations')->where('id', $mine['id'])->value('address_sealed'))->toBeNull()
        ->and(DB::table('invitations')->where('id', $notMine['id'])->value('status'))->toBe('pending');

    $versions = DB::table('invitations')->whereIn('id', [$mine['id'], $link['id']])->pluck('version', 'id');
    expect($step->purge($this->d->id, purgeContextFor($this->d->id))->get('invitations_revoked'))->toBe(0)
        ->and(DB::table('invitations')->whereIn('id', [$mine['id'], $link['id']])->pluck('version', 'id')->all())->toBe($versions->all());
});

test('an owner revokes in their own name, a former owner is revoked for by the system', function () {
    $owned = pendingInvitation($this->d->id, $this->wallet->id, 'a@example.test');
    $handed = ownedWallet($this->other->id);
    addMember($handed->id, $this->d->id, 'editor');
    $sent = pendingInvitation($this->d->id, $handed->id, 'b@example.test');

    app(RevokeSentInvitations::class)->purge($this->d->id, purgeContextFor($this->d->id));

    expect(DB::table('wallet_activity')->where('wallet_id', $this->wallet->id)->where('action', 'invitation_revoked')->value('actor_user_id'))->toBe($this->d->id)
        ->and(DB::table('wallet_activity')->where('wallet_id', $handed->id)->where('action', 'invitation_revoked')->value('actor_user_id'))->toBeNull()
        ->and(DB::table('invitations')->whereIn('id', [$owned['id'], $sent['id']])->pluck('status')->unique()->all())->toBe(['revoked']);
});

test('an invitation of a deleted wallet is left to the wallet\'s own tombstone', function () {
    $gone = ownedWallet($this->d->id, ['deleted_at' => now()]);
    pendingInvitation($this->d->id, $gone->id, 'c@example.test');

    expect(app(RevokeSentInvitations::class)->purge($this->d->id, purgeContextFor($this->d->id))->get('invitations_revoked'))->toBe(0);
});

test('revoking stamps each invitation from its wallet\'s counter, one wallet at a time', function () {
    $invitation = pendingInvitation($this->d->id, $this->wallet->id, 'd@example.test');
    $before = (int) DB::table('wallets')->where('id', $this->wallet->id)->value('change_seq');

    app(RevokeSentInvitations::class)->purge($this->d->id, purgeContextFor($this->d->id));

    expect((int) DB::table('invitations')->where('id', $invitation['id'])->value('change_seq'))->toBeGreaterThan($before)
        ->and((int) DB::table('wallets')->where('id', $this->wallet->id)->value('change_seq'))->toBeGreaterThan((int) DB::table('invitations')->where('id', $invitation['id'])->value('change_seq'))
        ->and(WalletMember::query()->where('wallet_id', $this->wallet->id)->count())->toBe(1);
});
