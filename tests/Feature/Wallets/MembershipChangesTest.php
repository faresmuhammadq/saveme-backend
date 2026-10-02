<?php

use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sharing\Application\MemberService;
use App\Modules\Wallets\Application\Membership\MembershipService;
use App\Modules\Wallets\Domain\Membership\Refusals\OwnerMembershipImmutable;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * Membership writes through Wallets' MembershipService (spec 016, FR-015 to
 * FR-020; ADR-0017; invariants): stamps, versions, audit entries, activity
 * entries, one owner, nothing recorded by a departing member touched.
 */

beforeEach(function () {
    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    $this->service = app(MembershipService::class);
});

function userChangeSeq(string $userId): int
{
    return (int) User::query()->whereKey($userId)->value('change_seq');
}

function walletChangeSeq(string $walletId): int
{
    return (int) Wallet::query()->whereKey($walletId)->value('change_seq');
}

function membershipOf(string $walletId, string $userId): WalletMember
{
    return WalletMember::query()->where('wallet_id', $walletId)->where('user_id', $userId)->orderByDesc('created_at')->firstOrFail();
}

test('grant inserts version 1 stamped from the wallet counter and the member counter, the wallet taking the last', function () {
    $editor = person('editor@example.test');
    $walletBefore = walletChangeSeq($this->wallet->id);
    $userBefore = userChangeSeq($editor->id);

    $member = $this->service->grant($this->wallet->id, $editor->id, Role::Editor);

    $row = WalletMember::query()->findOrFail($member->id);
    expect($row->version)->toBe(1)
        ->and($row->role)->toBe('editor')
        ->and($row->change_seq)->toBeGreaterThan($walletBefore)
        ->and($row->member_change_seq)->toBe($userBefore + 1)
        ->and(userChangeSeq($editor->id))->toBe($userBefore + 1)
        ->and(walletChangeSeq($this->wallet->id))->toBeGreaterThan($row->change_seq);

    $audit = AuditEntry::query()->where('event', 'membership_granted')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->detail)->toMatchArray(['member_user_id' => $editor->id, 'reason' => 'invitation'])
        ->and(WalletActivity::query()->where('action', 'joined')->count())->toBe(1);
});

test('granting an active member returns it and writes nothing', function () {
    $editor = person('editor@example.test');
    $first = $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    $seqs = [walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)];

    $again = $this->service->grant($this->wallet->id, $editor->id, Role::Viewer);

    expect($again->id)->toBe($first->id)
        ->and($again->role)->toBe(Role::Editor)
        ->and([walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)])->toBe($seqs)
        ->and(AuditEntry::query()->where('event', 'membership_granted')->count())->toBe(1);
});

test('a role change moves the version and both stamps and records one audit and one activity entry', function () {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    $before = membershipOf($this->wallet->id, $editor->id);
    $userBefore = userChangeSeq($editor->id);

    $changed = $this->service->changeRole($this->wallet->id, $this->owner->id, $editor->id, Role::Viewer);

    $row = membershipOf($this->wallet->id, $editor->id);
    expect($changed->role)->toBe(Role::Viewer)
        ->and($row->version)->toBe($before->version + 1)
        ->and($row->change_seq)->toBeGreaterThan($before->change_seq)
        ->and($row->member_change_seq)->toBe($userBefore + 1)
        ->and(walletChangeSeq($this->wallet->id))->toBeGreaterThan($row->change_seq);

    $audit = AuditEntry::query()->where('event', 'role_changed')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->actor_id)->toBe($this->owner->id)
        ->and($audit[0]->detail)->toMatchArray(['member_user_id' => $editor->id, 'from' => 'editor', 'to' => 'viewer']);
    $activity = WalletActivity::query()->where('action', 'role_changed')->get();
    expect($activity)->toHaveCount(1)->and($activity[0]->role)->toBe('viewer');
});

test('naming the role already held changes nothing', function () {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    $seqs = [walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)];

    $this->service->changeRole($this->wallet->id, $this->owner->id, $editor->id, Role::Editor);

    expect([walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)])->toBe($seqs)
        ->and(AuditEntry::query()->where('event', 'role_changed')->count())->toBe(0);
});

test('removal and leaving tombstone with both stamps and one membership_revoked each', function (string $how) {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    $before = membershipOf($this->wallet->id, $editor->id);
    $userBefore = userChangeSeq($editor->id);

    $ended = $how === 'removed'
        ? $this->service->remove($this->wallet->id, $this->owner->id, $editor->id)
        : $this->service->leave($this->wallet->id, $editor->id);

    $row = WalletMember::query()->findOrFail($ended->id);
    expect($row->deleted_at)->not->toBeNull()
        ->and($row->version)->toBe($before->version + 1)
        ->and($row->change_seq)->toBeGreaterThan($before->change_seq)
        ->and($row->member_change_seq)->toBe($userBefore + 1)
        ->and(walletChangeSeq($this->wallet->id))->toBeGreaterThan($row->change_seq);

    $audit = AuditEntry::query()->where('event', 'membership_revoked')->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->detail)->toMatchArray(['member_user_id' => $editor->id, 'reason' => $how]);
    $activity = WalletActivity::query()->where('action', $how === 'removed' ? 'removed' : 'left')->get();
    expect($activity)->toHaveCount(1)->and($activity[0]->role)->toBeNull();
})->with(['removed', 'left']);

test('a replay of a removal returns the tombstone and writes nothing; a repeated leave is not-found', function () {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    $first = $this->service->remove($this->wallet->id, $this->owner->id, $editor->id);
    $seqs = [walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)];

    $again = $this->service->remove($this->wallet->id, $this->owner->id, $editor->id);

    expect($again->id)->toBe($first->id)
        ->and($again->version)->toBe($first->version)
        ->and([walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)])->toBe($seqs)
        ->and(AuditEntry::query()->where('event', 'membership_revoked')->count())->toBe(1)
        ->and(fn () => $this->service->leave($this->wallet->id, $editor->id))->toThrow(WalletNotFound::class);
});

test('the owner cannot be tombstoned or given a role through these writes, so there is always exactly one owner', function () {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);

    expect(fn () => $this->service->remove($this->wallet->id, $editor->id, $this->owner->id))->toThrow(WalletActionForbidden::class)
        ->and(fn () => $this->service->leave($this->wallet->id, $this->owner->id))->toThrow(WalletActionForbidden::class)
        ->and(fn () => $this->service->changeRole($this->wallet->id, $this->owner->id, $this->owner->id, Role::Viewer))->toThrow(OwnerMembershipImmutable::class)
        ->and(fn () => $this->service->changeRole($this->wallet->id, $this->owner->id, $editor->id, Role::Owner))->toThrow(OwnerMembershipImmutable::class)
        ->and(fn () => $this->service->grant($this->wallet->id, person('x@example.test')->id, Role::Owner))->toThrow(OwnerMembershipImmutable::class);

    expect(WalletMember::query()->where('wallet_id', $this->wallet->id)->where('role', 'owner')->whereNull('deleted_at')->count())->toBe(1);
});

test('demotion turns the editors beyond the quota into viewers, by the system, people stamped in ascending user id', function () {
    $people = collect(range(1, 4))->map(fn (int $n) => person("editor{$n}@example.test"))->all();
    foreach ($people as $person) {
        $this->service->grant($this->wallet->id, $person->id, Role::Editor);
    }

    // Quota 3: the owner and the two earliest members stay.
    $demoted = $this->service->demoteBeyond($this->wallet->id, 3);

    expect(array_map(fn ($member): string => $member->userId, $demoted))->toEqualCanonicalizing([$people[2]->id, $people[3]->id]);
    expect(membershipOf($this->wallet->id, $people[2]->id)->role)->toBe('viewer')
        ->and(membershipOf($this->wallet->id, $people[3]->id)->role)->toBe('viewer')
        ->and(membershipOf($this->wallet->id, $people[1]->id)->role)->toBe('editor');

    $ids = collect([$people[2]->id, $people[3]->id])->sort()->values();
    $seqs = $ids->map(fn (string $id): int => membershipOf($this->wallet->id, $id)->change_seq)->all();
    expect($seqs)->toBe([min($seqs), max($seqs)]);

    $audit = AuditEntry::query()->where('event', 'role_changed')->get();
    expect($audit)->toHaveCount(2)
        ->and($audit->pluck('actor_type')->unique()->all())->toBe(['system'])
        ->and($audit->pluck('actor_id')->filter()->all())->toBe([]);
    $activity = WalletActivity::query()->where('action', 'role_limited_by_plan')->get();
    expect($activity)->toHaveCount(2)->and($activity->pluck('actor_user_id')->filter()->all())->toBe([]);
});

test('running the demotion again changes nothing and promotes nobody', function () {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    $this->service->demoteBeyond($this->wallet->id, 1);
    $seqs = [walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)];

    expect($this->service->demoteBeyond($this->wallet->id, 1))->toBe([])
        ->and($this->service->demoteBeyond($this->wallet->id, null))->toBe([])
        ->and([walletChangeSeq($this->wallet->id), userChangeSeq($editor->id)])->toBe($seqs)
        ->and(membershipOf($this->wallet->id, $editor->id)->role)->toBe('viewer');
});

test('what a departing member recorded is untouched', function () {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    $transaction = Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'created_by' => $editor->id]);
    $category = Category::query()->findOrFail($transaction->category_id);

    $this->service->leave($this->wallet->id, $editor->id);

    $transaction->refresh();
    expect($transaction->created_by)->toBe($editor->id)
        ->and($transaction->deleted_at)->toBeNull()
        ->and($transaction->version)->toBe(1)
        ->and($category->fresh()->version)->toBe($category->version);
});

test('writes in a deleted wallet are not-found', function () {
    $editor = person('editor@example.test');
    $this->service->grant($this->wallet->id, $editor->id, Role::Editor);
    Wallet::query()->whereKey($this->wallet->id)->update(['deleted_at' => now()]);

    expect(fn () => $this->service->changeRole($this->wallet->id, $this->owner->id, $editor->id, Role::Viewer))->toThrow(WalletNotFound::class)
        ->and(fn () => $this->service->leave($this->wallet->id, $editor->id))->toThrow(WalletNotFound::class)
        ->and($this->service->demoteBeyond($this->wallet->id, 1))->toBe([]);
});

describe('leaving a shared wallet (spec 016, US6; R-15)', function () {
    beforeEach(function () {
        freezeClock('2026-10-05 10:00:00');
        $this->ownerOfShared = $this->owner;
        $this->shared = $this->wallet;
        $this->editor = person('leaver@example.test');
        addMember($this->shared->id, $this->editor->id, 'editor');
    });

    test('the leaver\'s transactions, rules and category references all stay', function () {
        $transaction = spend($this->editor->id, $this->shared->id, 12000)->transaction;
        $rule = createRecurringRule($this->editor->id, $this->shared->id, recurringRuleBody())->rule;
        $references = CategoryReference::query()->where('wallet_id', $this->shared->id)->count();

        app(MemberService::class)->leave($this->editor->id, $this->shared->id, 1, fn () => []);

        expect(Transaction::query()->findOrFail($transaction->id))->created_by->toBe($this->editor->id)->deleted_at->toBeNull()
            ->and(RecurringRule::query()->findOrFail($rule->id))->created_by->toBe($this->editor->id)->deleted_at->toBeNull()
            ->and(CategoryReference::query()->where('wallet_id', $this->shared->id)->count())->toBe($references);
    });

    test('the leaver\'s budgets stop counting the wallet and the others\' are unchanged', function () {
        $category = platformCategoryId();
        $mine = createBudget($this->editor->id, ['category_id' => $category]);
        $theirs = createBudget($this->ownerOfShared->id, ['category_id' => $category]);
        spend($this->editor->id, $this->shared->id, 25000);
        spend($this->ownerOfShared->id, $this->shared->id, 9000);
        expect(budgetProgress($this->editor->id, $mine->id)->spent->amountMinor)->toBe(25000);

        app(MemberService::class)->leave($this->editor->id, $this->shared->id, 1, fn () => []);

        expect(budgetProgress($this->editor->id, $mine->id)->spent->amountMinor)->toBe(0)
            ->and(budgetProgress($this->ownerOfShared->id, $theirs->id)->spent->amountMinor)->toBe(9000);
    });

    test('a rule the leaver authored auto-confirms as not_permitted afterwards, and a member may still confirm it', function () {
        $rule = createRecurringRule($this->editor->id, $this->shared->id, recurringRuleBody(['auto_confirm' => true]))->rule;
        $occurrence = occurrencesOf($rule->id)->first();

        app(MemberService::class)->leave($this->editor->id, $this->shared->id, 1, fn () => []);

        freezeClock('2026-11-01 08:00:00');
        runRecurringJob();

        expect($occurrence->fresh()->auto_confirm_refusal)->toBe('not_permitted')
            ->and($occurrence->fresh()->status)->toBe('scheduled');
    });
});
