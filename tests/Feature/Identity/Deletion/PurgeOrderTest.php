<?php

use App\Modules\Identity\Domain\Events\ProfileUpdated;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * The order that makes the purge safe (spec 022, FR-040, FR-043, FR-044): the
 * name is erased and announced before any membership is tombstoned,
 * invitations are revoked before the data key is destroyed, memberships end
 * with the wallet's and the person's own stamps.
 */

beforeEach(function () {
    Mail::fake();
    $this->world = exportWorld();
    $this->d = $this->world['d'];
});

/**
 * Records, in order, the statements the purge runs and the events it raises.
 *
 * @return array<int, string>
 */
function recordPurgeTrace(Closure $run): array
{
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = strtolower($query->sql);
    });
    Event::listen(ProfileUpdated::class, function (ProfileUpdated $event) use (&$trace): void {
        $trace[] = 'event:profile_updated:'.implode(',', $event->changedFields);
    });

    $run();

    return $trace;
}

function firstIndex(array $trace, Closure $matches): int
{
    foreach ($trace as $index => $line) {
        if ($matches($line)) {
            return $index;
        }
    }

    return -1;
}

test('ProfileUpdated naming display_name is dispatched before the first membership is tombstoned', function () {
    $names = [];
    $alreadyEnded = WalletMember::query()->where('user_id', $this->d->id)->whereNotNull('deleted_at')->count();
    Event::listen(ProfileUpdated::class, function (ProfileUpdated $event) use (&$names): void {
        $names[] = [
            'fields' => $event->changedFields,
            'tombstoned_at_that_moment' => WalletMember::query()->where('user_id', $this->d->id)->whereNotNull('deleted_at')->count(),
        ];
    });

    purgeAccountNow($this->d->id);

    $mine = collect($names);
    expect($mine)->toHaveCount(1)
        ->and($mine[0]['fields'])->toBe(['display_name'])
        ->and($mine[0]['tombstoned_at_that_moment'])->toBe($alreadyEnded)
        ->and(WalletMember::query()->where('user_id', $this->d->id)->whereNull('deleted_at')->count())->toBe(0);
});

test('the name is gone from the profile before anything else is removed', function () {
    $trace = recordPurgeTrace(fn () => purgeAccountNow($this->d->id));

    $nameErased = firstIndex($trace, fn (string $line): bool => str_starts_with($line, 'update "profiles"') && str_contains($line, 'display_name_ciphertext'));
    $firstTombstone = firstIndex($trace, fn (string $line): bool => str_starts_with($line, 'update "wallet_members"') && str_contains($line, 'deleted_at'));

    expect($nameErased)->toBeGreaterThanOrEqual(0)->and($firstTombstone)->toBeGreaterThan($nameErased)
        ->and(firstIndex($trace, fn (string $line): bool => $line === 'event:profile_updated:display_name'))->toBeLessThan($firstTombstone);
});

test('a person with no name publishes no ProfileUpdated at all', function () {
    $nameless = namedPerson('nameless@example.test', null);
    $wallet = ownedWallet($nameless->id);
    addMember($wallet->id, $this->world['p']->id, 'viewer');
    Event::fake([ProfileUpdated::class]);

    purgeAccountNow($nameless->id);

    Event::assertNotDispatched(ProfileUpdated::class);
});

test('invitations are revoked, their address erased, before the data key is deleted', function () {
    $invitation = pendingInvitation($this->d->id, $this->world['s']->id, 'invitee@example.test');

    $trace = recordPurgeTrace(fn () => purgeAccountNow($this->d->id));

    $revoked = firstIndex($trace, fn (string $line): bool => str_starts_with($line, 'update "invitations"'));
    $keyDeleted = firstIndex($trace, fn (string $line): bool => str_starts_with($line, 'delete from "user_keys"'));

    expect($revoked)->toBeGreaterThanOrEqual(0)->and($keyDeleted)->toBeGreaterThan($revoked);

    $stored = DB::table('invitations')->where('id', $invitation['id'])->first();
    expect($stored->status)->toBe('revoked')->and($stored->address_sealed)->toBeNull()->and($stored->address_lookup)->toBeNull();
});

test('an invitation sent from a wallet the person no longer owns is revoked by the system', function () {
    $wallet = ownedWallet($this->d->id);
    $sent = pendingInvitation($this->d->id, $wallet->id, 'later@example.test');
    addMember($wallet->id, $this->world['p']->id, 'editor');
    WalletMember::query()->where('wallet_id', $wallet->id)->where('user_id', $this->d->id)->update(['role' => 'editor']);
    WalletMember::query()->where('wallet_id', $wallet->id)->where('user_id', $this->world['p']->id)->update(['role' => 'owner']);

    purgeAccountNow($this->d->id);

    expect(DB::table('invitations')->where('id', $sent['id'])->value('status'))->toBe('revoked')
        ->and(DB::table('wallet_activity')->where('wallet_id', $wallet->id)->where('action', 'invitation_revoked')->value('actor_user_id'))->toBeNull();
});

test('memberships end like leaving: wallet stamp, the person\'s own stamp, one membership_revoked each', function () {
    $before = WalletMember::query()->where('user_id', $this->d->id)->whereNull('deleted_at')->get()->keyBy('wallet_id');
    $userSeqBefore = (int) DB::table('users')->where('id', $this->d->id)->value('change_seq');

    purgeAccountNow($this->d->id);

    $ended = WalletMember::query()->where('user_id', $this->d->id)->whereNotNull('deleted_at')->get();
    $revokedEntries = AuditEntry::query()->where('event', 'membership_revoked')->where('actor_id', $this->d->id)->get();

    expect($ended->count())->toBeGreaterThanOrEqual(2);

    foreach ($ended as $member) {
        $was = $before[$member->wallet_id] ?? null;

        if ($was !== null) {
            expect($member->version)->toBeGreaterThan($was->version)
                ->and($member->change_seq)->toBeGreaterThan($was->change_seq)
                ->and($member->member_change_seq)->toBeGreaterThan($was->member_change_seq);
        }
    }

    expect($revokedEntries->pluck('subject_id')->sort()->values()->all())->toBe($ended->where('role', '!=', 'owner')->pluck('wallet_id')->unique()->sort()->values()->all());
});
