<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Sharing\Application\ExpireInvitations;
use App\Modules\Sharing\Application\InvitationsGoWithWallet;
use App\Modules\Sharing\Application\Sync\TombstonePurge;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Sync\Application\TombstonePurgeRun;
use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * The life of an invitation after it is sent (spec 016, US3, US7; FR-007,
 * FR-008, FR-021 to FR-024, R-14): revoke, expiry sweep, wallet deletion,
 * purge.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
});

function revokeInvitation(string $credential, string $walletId, string $invitationId, int $version = 1): TestResponse
{
    return walletCall('DELETE', $credential, '/wallets/'.$walletId.'/invitations/'.$invitationId, ['version' => $version]);
}

test('revoking closes the invitation as revoked, erases the address and frees the seat', function () {
    Queue::fake();
    $ids = [];
    foreach (range(1, 4) as $n) {
        $ids[] = $id = (string) Str::uuid7();
        sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => "p{$n}@example.test"], $id)->assertCreated();
    }
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'late@example.test'])->assertForbidden();
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;

    $response = revokeInvitation($this->credential, $this->wallet->id, $ids[0])->assertOk();

    $row = Invitation::query()->findOrFail($ids[0]);
    expect($response->json('data.status'))->toBe('revoked')
        ->and($row->deleted_at)->not->toBeNull()
        ->and($row->version)->toBe(2)
        ->and($row->address_sealed)->toBeNull()
        ->and($row->address_lookup)->toBeNull()
        ->and($row->change_seq)->toBeGreaterThan($seq)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBeGreaterThan($row->change_seq);

    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'late@example.test'])->assertCreated();
});

test('a revoked invitation\'s token is dead', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    [, $invitee] = walletCaller('invitee@example.test');

    revokeInvitation($this->credential, $this->wallet->id, $invitation['id'])->assertOk();

    respondToInvitation($invitee, 'accept', $invitation['token'])->assertNotFound();
    respondToInvitation($invitee, 'preview', $invitation['token'])->assertNotFound();
});

test('an owner whose plan lapsed can still withdraw an offer', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    Subscription::query()->where('user_id', $this->owner->id)->delete();
    app()->forgetScopedInstances();

    revokeInvitation($this->credential, $this->wallet->id, $invitation['id'])->assertOk();

    expect(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('revoked');
});

test('revoking a closed invitation returns it unchanged, whatever the version; a stale version is a conflict', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');

    $stale = revokeInvitation($this->credential, $this->wallet->id, $invitation['id'], 9)->assertStatus(409);
    expect($stale->json('current.status'))->toBe('pending');

    $first = revokeInvitation($this->credential, $this->wallet->id, $invitation['id'])->assertOk();
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;
    $again = revokeInvitation($this->credential, $this->wallet->id, $invitation['id'], 9)->assertOk();

    expect($again->json('data'))->toBe($first->json('data'))
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBe($seq);

    Invitation::query()->whereKey($invitation['id'])->update(['status' => 'accepted']);
    revokeInvitation($this->credential, $this->wallet->id, $invitation['id'])->assertOk()->assertJsonPath('data.status', 'accepted');
});

test('only a revoke records activity, and it records one entry', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    $other = pendingInvitation($this->owner->id, $this->wallet->id, 'other@example.test');
    Invitation::query()->whereKey($other['id'])->update(['expires_at' => now()->subHour()]);
    app(ExpireInvitations::class)->run();

    expect(WalletActivity::query()->count())->toBe(0);

    revokeInvitation($this->credential, $this->wallet->id, $invitation['id'])->assertOk();
    revokeInvitation($this->credential, $this->wallet->id, $invitation['id'])->assertOk();

    $entries = WalletActivity::query()->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->action)->toBe('invitation_revoked')
        ->and($entries[0]->subject_id)->toBe($invitation['id'])
        ->and($entries[0]->actor_user_id)->toBe($this->owner->id);
});

test('the sweep closes invitations past their expiry as expired with stamps and erasure, and is idempotent', function () {
    $due = pendingInvitation($this->owner->id, $this->wallet->id, 'a@example.test');
    $link = pendingInvitation($this->owner->id, $this->wallet->id, null);
    $fresh = pendingInvitation($this->owner->id, $this->wallet->id, 'b@example.test');
    Invitation::query()->whereIn('id', [$due['id'], $link['id']])->update(['expires_at' => now()->subSecond()]);
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;

    $closed = app(ExpireInvitations::class)->run();

    $rows = Invitation::query()->whereIn('id', [$due['id'], $link['id']])->get();
    expect($closed)->toBe(2)
        ->and($rows->pluck('status')->unique()->all())->toBe(['expired'])
        ->and($rows->pluck('address_sealed')->filter()->all())->toBe([])
        ->and($rows->pluck('address_lookup')->filter()->all())->toBe([])
        ->and($rows->pluck('change_seq')->unique())->toHaveCount(2)
        ->and($rows->min('change_seq'))->toBeGreaterThan($seq)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBeGreaterThan($rows->max('change_seq'))
        ->and(Invitation::query()->findOrFail($fresh['id'])->status)->toBe('pending')
        ->and(app(ExpireInvitations::class)->run())->toBe(0);
});

test('the sweep works through more than one batch and across wallets', function () {
    $second = ownedWallet($this->owner->id);
    foreach (range(1, 5) as $n) {
        $invitation = pendingInvitation($this->owner->id, $n % 2 === 0 ? $this->wallet->id : $second->id, null);
        Invitation::query()->whereKey($invitation['id'])->update(['expires_at' => now()->subHour()]);
    }

    expect(app(ExpireInvitations::class)->run())->toBe(5)
        ->and(Invitation::query()->whereNull('deleted_at')->count())->toBe(0);
});

test('the console command runs the sweep', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    Invitation::query()->whereKey($invitation['id'])->update(['expires_at' => now()->subHour()]);

    $this->artisan('sharing:expire-invitations')->assertSuccessful();

    expect(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('expired');
});

test('the sweep is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'sharing:expire-invitations'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');
});

test('deleting the wallet tombstones its pending invitations as revoked in the same unit of work', function () {
    $pending = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    $closed = pendingInvitation($this->owner->id, $this->wallet->id, 'closed@example.test');
    Invitation::query()->whereKey($closed['id'])->update(['status' => 'declined', 'deleted_at' => now()->subDay(), 'address_sealed' => null, 'address_lookup' => null]);

    app(WalletService::class)->delete($this->owner->id, $this->wallet->id, 1, presentWallet());

    $row = Invitation::query()->findOrFail($pending['id']);
    expect($row->status)->toBe('revoked')
        ->and($row->deleted_at)->not->toBeNull()
        ->and($row->address_sealed)->toBeNull()
        ->and($row->version)->toBe(2)
        ->and(Invitation::query()->findOrFail($closed['id'])->status)->toBe('declined')
        ->and($row->change_seq)->toBeLessThan(Wallet::query()->findOrFail($this->wallet->id)->change_seq);
});

test('a failure later in the deletion rolls the invitations back with everything else', function () {
    $pending = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    $failing = new class implements WalletDeletionParticipant
    {
        public function tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void
        {
            throw new RuntimeException('a later participant failed');
        }
    };
    $service = app()->makeWith(WalletService::class, ['participants' => [app(InvitationsGoWithWallet::class), $failing]]);

    expect(fn () => $service->delete($this->owner->id, $this->wallet->id, 1, presentWallet()))->toThrow(RuntimeException::class);

    expect(Invitation::query()->findOrFail($pending['id'])->status)->toBe('pending')
        ->and(Wallet::query()->findOrFail($this->wallet->id)->deleted_at)->toBeNull();
});

test('the purge removes invitation tombstones older than the horizon, raises the wallet\'s horizon and keeps younger ones', function () {
    $old = pendingInvitation($this->owner->id, $this->wallet->id, null);
    $young = pendingInvitation($this->owner->id, $this->wallet->id, null);
    $live = pendingInvitation($this->owner->id, $this->wallet->id, null);
    revokeInvitation($this->credential, $this->wallet->id, $old['id'])->assertOk();
    revokeInvitation($this->credential, $this->wallet->id, $young['id'])->assertOk();
    Invitation::query()->whereKey($old['id'])->update(['deleted_at' => '2026-06-01 10:00:00']);
    Invitation::query()->whereKey($young['id'])->update(['deleted_at' => '2026-09-20 10:00:00']);
    $oldSeq = Invitation::query()->findOrFail($old['id'])->change_seq;

    $removed = app(TombstonePurgeRun::class)->handle();

    expect($removed['sharing_invitations'])->toBe(1)
        ->and(Invitation::query()->whereKey($old['id'])->exists())->toBeFalse()
        ->and(Invitation::query()->whereKey([$young['id'], $live['id']])->count())->toBe(2)
        ->and((int) DB::table('wallets')->where('id', $this->wallet->id)->value('purged_change_seq'))->toBe($oldSeq);

    expect(app(TombstonePurgeRun::class)->handle()['sharing_invitations'])->toBe(0);
});

test('the purge resumes across batches', function () {
    foreach (range(1, 3) as $n) {
        $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
        Invitation::query()->whereKey($invitation['id'])->update(['status' => 'revoked', 'deleted_at' => '2026-06-01 10:00:00']);
    }

    $first = app(TombstonePurge::class)->purge(now()->toDateTimeImmutable()->modify('-90 days'), 2);

    expect($first)->toBe(['invitations' => 3])->and(Invitation::query()->count())->toBe(0);
});

test('a deleted wallet whose only remaining rows were invitation tombstones is purged in the same run', function () {
    $wallet = ownedWallet($this->owner->id);
    pendingInvitation($this->owner->id, $wallet->id, null);
    app(WalletService::class)->delete($this->owner->id, $wallet->id, 1, presentWallet());
    DB::table('invitations')->where('wallet_id', $wallet->id)->update(['deleted_at' => '2026-06-01 10:00:00']);
    DB::table('wallet_members')->where('wallet_id', $wallet->id)->delete();
    DB::table('wallets')->where('id', $wallet->id)->update(['deleted_at' => '2026-06-01 10:00:00']);

    $removed = app(TombstonePurgeRun::class)->handle();

    expect($removed['sharing_invitations'])->toBe(1)
        ->and($removed['wallets'])->toBe(1)
        ->and(Wallet::query()->whereKey($wallet->id)->exists())->toBeFalse();
});
