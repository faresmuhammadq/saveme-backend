<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Wallets\Domain\Events\WalletDeleted;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * What happens to the wallets a departing person owns (spec 022, FR-030 to
 * FR-032, FR-045, FR-064): a shared wallet is never deleted, it passes to one
 * successor; only a wallet nobody else belongs to goes.
 */

beforeEach(function () {
    Mail::fake();
    $this->d = namedPerson('ada@example.test', 'Ada');
    $this->p = namedPerson('pat@example.test', 'Pat');
    $this->q = namedPerson('quinn@example.test', 'Quinn');
});

function ownerOf(string $walletId): ?string
{
    return WalletMember::query()->where('wallet_id', $walletId)->where('role', 'owner')->whereNull('deleted_at')->value('user_id');
}

function joined(string $walletId, string $userId, string $at): void
{
    WalletMember::query()->where('wallet_id', $walletId)->where('user_id', $userId)->update(['created_at' => $at]);
}

test('one other member, a viewer, becomes owner directly', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'viewer');

    purgeAccountNow($this->d->id);

    expect(ownerOf($wallet->id))->toBe($this->p->id)
        ->and(Wallet::query()->find($wallet->id)->deleted_at)->toBeNull();
});

test('a valid choice is honoured among several members', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'editor');
    addMember($wallet->id, $this->q->id, 'viewer');
    DB::table('deletion_successor_choices')->insert(['user_id' => $this->d->id, 'wallet_id' => $wallet->id, 'member_user_id' => $this->q->id, 'chosen_at' => now()]);

    purgeAccountNow($this->d->id);

    expect(ownerOf($wallet->id))->toBe($this->q->id);
});

test('a choice that has left falls back to the longest-standing editor', function () {
    $wallet = ownedWallet($this->d->id);
    $third = person('third@example.test');
    addMember($wallet->id, $this->p->id, 'editor');
    addMember($wallet->id, $this->q->id, 'editor');
    addMember($wallet->id, $third->id, 'viewer');
    joined($wallet->id, $this->p->id, '2026-03-01');
    joined($wallet->id, $this->q->id, '2026-02-01');
    DB::table('deletion_successor_choices')->insert(['user_id' => $this->d->id, 'wallet_id' => $wallet->id, 'member_user_id' => $third->id, 'chosen_at' => now()]);
    WalletMember::query()->where('wallet_id', $wallet->id)->where('user_id', $third->id)->update(['deleted_at' => now()]);

    purgeAccountNow($this->d->id);

    expect(ownerOf($wallet->id))->toBe($this->q->id);
});

test('with no editor the longest-standing viewer inherits', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'viewer');
    addMember($wallet->id, $this->q->id, 'viewer');
    joined($wallet->id, $this->p->id, '2026-03-01');
    joined($wallet->id, $this->q->id, '2026-02-01');

    purgeAccountNow($this->d->id);

    expect(ownerOf($wallet->id))->toBe($this->q->id)
        ->and(WalletMember::query()->where('wallet_id', $wallet->id)->where('user_id', $this->p->id)->value('role'))->toBe('viewer');
});

test('an editor whose own account is leaving is skipped while another editor stays', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'editor');
    addMember($wallet->id, $this->q->id, 'editor');
    joined($wallet->id, $this->p->id, '2026-01-01');
    joined($wallet->id, $this->q->id, '2026-02-01');
    dueForPurge($this->p);

    purgeAccountNow($this->d->id);

    expect(ownerOf($wallet->id))->toBe($this->q->id);
});

test('a solo wallet, archived or not, is deleted with its contents and announced', function () {
    Event::fake([WalletDeleted::class]);
    $solo = ownedWallet($this->d->id);
    $archived = ownedWallet($this->d->id, ['archived_at' => now()]);
    $entry = recordTransaction($this->d->id, $solo->id)->transaction;

    purgeAccountNow($this->d->id);

    expect(Wallet::query()->find($solo->id)->deleted_at)->not->toBeNull()
        ->and(Wallet::query()->find($archived->id)->deleted_at)->not->toBeNull()
        ->and(Transaction::query()->find($entry->id)->deleted_at)->not->toBeNull();
    Event::assertDispatchedTimes(WalletDeleted::class, 2);
});

test('an archived shared wallet is transferred, not deleted', function () {
    $wallet = ownedWallet($this->d->id, ['archived_at' => now()]);
    addMember($wallet->id, $this->p->id, 'editor');

    purgeAccountNow($this->d->id);

    $stored = Wallet::query()->find($wallet->id);
    expect($stored->deleted_at)->toBeNull()->and($stored->archived_at)->not->toBeNull()
        ->and(ownerOf($wallet->id))->toBe($this->p->id);
});

test('each transfer writes one role_changed entry and one activity entry in the departing person\'s name, and nobody else is removed', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'editor');
    addMember($wallet->id, $this->q->id, 'viewer');

    purgeAccountNow($this->d->id);

    $roleChanged = AuditEntry::query()->where('event', 'role_changed')->where('subject_id', $wallet->id)->where('actor_type', 'user')->get();
    $transferred = WalletActivity::query()->where('wallet_id', $wallet->id)->where('action', 'ownership_transferred')->get();

    expect($roleChanged)->toHaveCount(1)
        ->and($roleChanged[0]->actor_type)->toBe('user')
        ->and($roleChanged[0]->actor_id)->toBe($this->d->id)
        ->and($transferred)->toHaveCount(1)
        ->and($transferred[0]->actor_user_id)->toBe($this->d->id)
        ->and(WalletMember::query()->where('wallet_id', $wallet->id)->whereNull('deleted_at')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->p->id, $this->q->id])->sort()->values()->all());
});

test('the plan-demotion rule applies under a new owner on the free plan, and nobody is removed', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'editor');
    addMember($wallet->id, $this->q->id, 'editor');
    joined($wallet->id, $this->p->id, '2026-01-01');
    joined($wallet->id, $this->q->id, '2026-02-01');

    purgeAccountNow($this->d->id);

    $roles = WalletMember::query()->where('wallet_id', $wallet->id)->whereNull('deleted_at')->pluck('role', 'user_id');
    expect($roles[$this->p->id])->toBe('owner')
        ->and($roles[$this->q->id])->toBe('viewer')
        ->and($roles)->toHaveCount(2)
        ->and(WalletActivity::query()->where('wallet_id', $wallet->id)->where('action', 'role_limited_by_plan')->whereNull('actor_user_id')->count())->toBeGreaterThanOrEqual(1);
});

test('the acts the person\'s own role allows name them; session, subscription and completion entries name the system', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'editor');
    $other = ownedWallet($this->p->id);
    addMember($other->id, $this->d->id, 'editor');
    $solo = ownedWallet($this->d->id);
    $credential = signInAs('ada@example.test')->json('data.credential');
    pendingInvitation($this->d->id, $solo->id, 'x@example.test');
    $subscription = Subscription::factory()->create(['user_id' => $this->d->id, 'provider' => 'manual', 'status' => 'active']);

    purgeAccountNow($this->d->id);

    $actors = AuditEntry::query()->orderBy('occurred_at')->get()->groupBy('event')->map(fn ($entries) => $entries->pluck('actor_type')->unique()->values()->all());

    expect($actors['membership_revoked'])->toBe(['user'])
        ->and($actors['role_changed'])->toContain('user')
        ->and($actors['session_revoked'])->toBe(['system'])
        ->and($actors['deletion_completed'])->toBe(['system'])
        ->and(AuditEntry::query()->where('event', 'entitlement_changed')->where('subject_id', $subscription->id)->value('actor_type'))->toBe('system');

    expect(WalletActivity::query()->where('wallet_id', $solo->id)->where('action', 'invitation_revoked')->value('actor_user_id'))->toBe($this->d->id)
        ->and(WalletActivity::query()->where('wallet_id', $other->id)->where('action', 'left')->value('actor_user_id'))->toBe($this->d->id);
});

test('a wallet with another member is never deleted by its owner\'s purge, whatever the roles', function () {
    $wallets = [];
    foreach (['viewer', 'editor'] as $role) {
        $wallet = ownedWallet($this->d->id);
        addMember($wallet->id, $this->p->id, $role);
        $wallets[] = $wallet->id;
    }

    purgeAccountNow($this->d->id);

    foreach ($wallets as $id) {
        expect(Wallet::query()->find($id)->deleted_at)->toBeNull()->and(ownerOf($id))->toBe($this->p->id);
    }
});

test('two members purged in sequence: the second inherits at the first\'s purge, the wallet goes with the second', function () {
    $wallet = ownedWallet($this->d->id);
    addMember($wallet->id, $this->p->id, 'editor');

    purgeAccountNow($this->d->id);
    expect(ownerOf($wallet->id))->toBe($this->p->id)->and(Wallet::query()->find($wallet->id)->deleted_at)->toBeNull();

    purgeAccountNow($this->p->id);
    expect(Wallet::query()->find($wallet->id)->deleted_at)->not->toBeNull();
});
