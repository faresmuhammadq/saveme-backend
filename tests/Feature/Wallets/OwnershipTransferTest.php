<?php

use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use App\Modules\Wallets\Domain\Refusals\OwnershipTargetInvalid;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Ownership transfer (spec 010, US4; FR-015, FR-017, FR-023, R-10).
 */

beforeEach(function () {
    // The plan-demotion listener (spec 016) is queued; these tests are about the transfer itself, and the demotion has its own tests.
    Queue::fake();
    $this->owner = person();
    $this->editor = person('grace@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    $this->editorMember = addMember($this->wallet->id, $this->editor->id, 'editor');
});

function transfer(string $actorId, string $walletId, string $targetId, ?int $version = null): WalletView
{
    return app(WalletService::class)->transferOwnership($actorId, $walletId, $targetId, $version ?? Wallet::query()->find($walletId)->version, presentWallet());
}

function activeOwners(string $walletId): int
{
    return WalletMember::query()->where('wallet_id', $walletId)->where('role', 'owner')->whereNull('deleted_at')->count();
}

test('the target becomes owner, the actor becomes editor, and there is exactly one owner', function () {
    $before = Wallet::query()->find($this->wallet->id);
    $ownerMemberBefore = WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->owner->id)->first();

    $view = transfer($this->owner->id, $this->wallet->id, $this->editor->id);

    $roles = WalletMember::query()->where('wallet_id', $this->wallet->id)->pluck('role', 'user_id');
    expect($roles[$this->editor->id])->toBe('owner')
        ->and($roles[$this->owner->id])->toBe('editor')
        ->and(activeOwners($this->wallet->id))->toBe(1)
        ->and($view->myRole->value)->toBe('editor')
        ->and($view->wallet->version)->toBe($before->version + 1);

    foreach ([$this->editorMember->fresh(), $ownerMemberBefore->fresh()] as $member) {
        expect($member->version)->toBe(2);
    }
    expect($this->editorMember->fresh()->change_seq)->not->toBe($ownerMemberBefore->fresh()->change_seq)
        ->and($this->editorMember->fresh()->change_seq)->toBeGreaterThan($this->editorMember->change_seq);
});

test('exactly one role_changed entry, with identifiers only', function () {
    transfer($this->owner->id, $this->wallet->id, $this->editor->id);

    $entries = AuditEntry::query()->where('event', 'role_changed')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('user')
        ->and($entries[0]->actor_id)->toBe($this->owner->id)
        ->and($entries[0]->subject_type)->toBe('wallet')
        ->and($entries[0]->subject_id)->toBe($this->wallet->id)
        ->and($entries[0]->detail)->toEqual(['previous_owner_id' => $this->owner->id, 'new_owner_id' => $this->editor->id]);
});

test('WalletOwnershipTransferred follows the commit', function () {
    Event::fake([WalletOwnershipTransferred::class]);

    transfer($this->owner->id, $this->wallet->id, $this->editor->id);

    Event::assertDispatched(WalletOwnershipTransferred::class, fn (WalletOwnershipTransferred $event): bool => $event->previousOwnerId === $this->owner->id && $event->newOwnerId === $this->editor->id);
});

test('self, a non-member and a removed member are refused with nothing changed', function (string $who) {
    $stranger = person('stranger@example.test');
    $removed = person('removed@example.test');
    $member = addMember($this->wallet->id, $removed->id, 'viewer');
    DB::table('wallet_members')->where('id', $member->id)->update(['deleted_at' => now()]);
    $target = ['self' => $this->owner->id, 'stranger' => $stranger->id, 'removed' => $removed->id][$who];
    $before = Wallet::query()->find($this->wallet->id);

    expect(fn () => transfer($this->owner->id, $this->wallet->id, $target))->toThrow(OwnershipTargetInvalid::class);

    expect(Wallet::query()->find($this->wallet->id)->version)->toBe($before->version)
        ->and(activeOwners($this->wallet->id))->toBe(1)
        ->and(AuditEntry::query()->where('event', 'role_changed')->count())->toBe(0)
        ->and(WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->owner->id)->value('role'))->toBe('owner');
})->with(['self', 'stranger', 'removed']);

test('naming the person who already owns the wallet changes nothing and writes no entry', function () {
    transfer($this->owner->id, $this->wallet->id, $this->editor->id);
    $after = Wallet::query()->find($this->wallet->id);

    $view = transfer($this->owner->id, $this->wallet->id, $this->editor->id, 1);

    expect($view->myRole->value)->toBe('editor')
        ->and(Wallet::query()->find($this->wallet->id)->version)->toBe($after->version)
        ->and(Wallet::query()->find($this->wallet->id)->change_seq)->toBe($after->change_seq)
        ->and(AuditEntry::query()->where('event', 'role_changed')->count())->toBe(1);
});
