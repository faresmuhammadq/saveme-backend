<?php

use App\Modules\Billing\Application\Subscriptions\OperatorSubscriptions;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sharing\Application\EnforceMemberQuota;
use App\Modules\Sharing\Application\Listeners\DemoteOnLapse;
use App\Modules\Sharing\Application\Listeners\DemoteOnOwnershipTransfer;
use App\Modules\Sharing\Domain\Events\MemberRoleChanged;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;

/*
 * The owner's plan lapses (spec 016, US7; FR-019 to FR-021; research R-11;
 * P-09 rule 6): editors beyond the quota become viewers, nobody is removed,
 * nobody is promoted again.
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    $this->subscription = Subscription::query()->where('user_id', $this->owner->id)->firstOrFail();
    $this->editors = [];

    foreach (range(1, 4) as $n) {
        $person = person("editor{$n}@example.test");
        $member = addMember($this->wallet->id, $person->id, 'editor');
        WalletMember::query()->whereKey($member->id)->update(['created_at' => now()->addSeconds($n)]);
        $this->editors[] = $person;
    }
});

function memberRoles(string $walletId): array
{
    return WalletMember::query()->where('wallet_id', $walletId)->whereNull('deleted_at')->orderBy('created_at')->get()
        ->mapWithKeys(fn (WalletMember $member): array => [$member->user_id => $member->role])->all();
}

function lapseOwnerSubscription(object $test, string $to): void
{
    $subscription = $test->subscription;

    match ($to) {
        'expired' => $subscription->update(['status' => 'grace', 'current_period_end' => now()->subDays(4), 'grace_until' => now()->subHour()]),
        'grace' => $subscription->update(['current_period_end' => now()->subHour()]),
        'revoked' => null,
    };

    if ($to === 'revoked') {
        app(OperatorSubscriptions::class)->revoke((string) Str::uuid7(), $subscription->id, 'test');
    } else {
        test()->artisan('billing:lapse-subscriptions')->assertSuccessful();
    }

    app()->forgetScopedInstances();
}

test('lapsing to expired or revoked turns every non-owner editor into a viewer on Free, removing nobody', function (string $how) {
    lapseOwnerSubscription($this, $how);

    $roles = memberRoles($this->wallet->id);
    expect($roles)->toHaveCount(5)
        ->and($roles[$this->owner->id])->toBe('owner')
        ->and(collect($roles)->except($this->owner->id)->unique()->values()->all())->toBe(['viewer']);
})->with(['expired', 'revoked']);

test('a plan with a quota of 3 demotes the two latest-joined editors', function () {
    $plan = Plan::factory()->create(['features' => [
        'sharing.enabled' => true, 'sharing.max_members_per_wallet' => 3, 'export.enabled' => true, 'reports.advanced' => true, 'wallets.max' => null,
    ]]);
    $this->subscription->update(['plan_id' => $plan->id]);
    app()->forgetScopedInstances();

    $demoted = app(EnforceMemberQuota::class)->forOwner($this->owner->id);

    $roles = memberRoles($this->wallet->id);
    expect($demoted)->toBe(['wallets' => 1, 'demoted' => 2])
        ->and(array_values($roles))->toBe(['owner', 'editor', 'editor', 'viewer', 'viewer'])
        ->and($roles[$this->editors[2]->id])->toBe('viewer')
        ->and($roles[$this->editors[3]->id])->toBe('viewer');
});

test('a lapse into grace changes nothing because grace still grants', function () {
    lapseOwnerSubscription($this, 'grace');

    expect(Subscription::query()->findOrFail($this->subscription->id)->status)->toBe('grace')
        ->and(array_count_values(memberRoles($this->wallet->id)))->toBe(['owner' => 1, 'editor' => 4]);
});

test('the demotion is stamped on both feeds, audited by the system and recorded in the activity with no actor', function () {
    $before = Wallet::query()->findOrFail($this->wallet->id)->change_seq;
    $userBefore = (int) User::query()->whereKey($this->editors[0]->id)->value('change_seq');

    lapseOwnerSubscription($this, 'revoked');

    foreach ($this->editors as $editor) {
        $row = WalletMember::query()->where('user_id', $editor->id)->firstOrFail();
        expect($row->role)->toBe('viewer')->and($row->version)->toBe(2)->and($row->change_seq)->toBeGreaterThan($before);
    }

    expect((int) User::query()->whereKey($this->editors[0]->id)->value('change_seq'))->toBe($userBefore + 1)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBeGreaterThan(WalletMember::query()->where('wallet_id', $this->wallet->id)->max('change_seq'));

    $audit = AuditEntry::query()->where('event', 'role_changed')->get();
    expect($audit)->toHaveCount(4)
        ->and($audit->pluck('actor_type')->unique()->all())->toBe(['system'])
        ->and($audit[0]->detail)->toMatchArray(['from' => 'editor', 'to' => 'viewer']);
    $activity = WalletActivity::query()->where('action', 'role_limited_by_plan')->get();
    expect($activity)->toHaveCount(4)->and($activity->pluck('actor_user_id')->filter()->all())->toBe([])
        ->and($activity->pluck('role')->unique()->values()->all())->toBe(['viewer']);

    $karimCredential = signInAs('editor1@example.test')->json('data.credential');
    $own = collect(readFeedFully($karimCredential, 'user')['rows'])->where('type', 'wallet_member')->first();
    expect($own['record'])->toMatchArray(['role' => 'viewer', 'deleted_at' => null]);
    walletCall('GET', $karimCredential, '/wallets/'.$this->wallet->id)->assertOk();
});

test('one MemberRoleChanged per demoted member, reason plan', function () {
    Event::fake([MemberRoleChanged::class]);

    app(EnforceMemberQuota::class)->forWallet($this->wallet->id);

    Event::assertNotDispatched(MemberRoleChanged::class);

    Subscription::query()->where('user_id', $this->owner->id)->update(['status' => 'expired', 'current_period_end' => now()->subDays(9), 'grace_until' => now()->subDays(6)]);
    app()->forgetScopedInstances();
    app(EnforceMemberQuota::class)->forWallet($this->wallet->id);

    Event::assertDispatchedTimes(MemberRoleChanged::class, 4);
    Event::assertDispatched(MemberRoleChanged::class, fn (MemberRoleChanged $event): bool => $event->reason === 'plan' && $event->previousRole === 'editor' && $event->role === 'viewer' && $event->actorUserId === null);
});

test('running the enforcement again changes nothing, and re-subscribing promotes nobody', function () {
    lapseOwnerSubscription($this, 'revoked');
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;

    expect(app(EnforceMemberQuota::class)->forWallet($this->wallet->id))->toBe(0)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBe($seq);

    Subscription::factory()->create(['user_id' => $this->owner->id]);
    app()->forgetScopedInstances();
    app(EnforceMemberQuota::class)->forOwner($this->owner->id);

    expect(collect(memberRoles($this->wallet->id))->except($this->owner->id)->unique()->values()->all())->toBe(['viewer'])
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBe($seq);
});

test('an ownership transfer to an owner on Free demotes the editors', function () {
    $free = person('free.owner@example.test');
    $member = addMember($this->wallet->id, $free->id, 'editor');
    WalletMember::query()->whereKey($member->id)->update(['created_at' => now()->addSeconds(9)]);

    app(WalletService::class)->transferOwnership($this->owner->id, $this->wallet->id, $free->id, Wallet::query()->findOrFail($this->wallet->id)->version, presentWallet());

    $roles = memberRoles($this->wallet->id);
    expect($roles[$free->id])->toBe('owner')
        ->and($roles[$this->owner->id])->toBe('viewer')
        ->and(collect($roles)->except($free->id)->unique()->values()->all())->toBe(['viewer'])
        ->and(count($roles))->toBe(6);
});

test('pending invitations survive a downgrade but cannot be accepted', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    [, $invitee] = walletCaller('invitee@example.test');

    lapseOwnerSubscription($this, 'revoked');

    expect(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('pending');
    $refused = respondToInvitation($invitee, 'accept', $invitation['token'])->assertForbidden();
    expect($refused->json('feature'))->toBe('sharing.enabled');
});

test('the owner can still revoke and remove after a downgrade, and new invitations are refused', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    lapseOwnerSubscription($this, 'revoked');

    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$invitation['id'], ['version' => 1])->assertOk();
    $member = WalletMember::query()->where('user_id', $this->editors[0]->id)->firstOrFail();
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.$this->editors[0]->id, ['version' => $member->version])->assertOk();
    sendInvitation($this->credential, $this->wallet->id)->assertForbidden()->assertJsonPath('feature', 'sharing.enabled');
});

test('the listeners are queued, and tolerate a wallet deleted meanwhile', function () {
    expect(new DemoteOnLapse(app(EnforceMemberQuota::class)))->toBeInstanceOf(ShouldQueue::class)
        ->and(new DemoteOnOwnershipTransfer(app(EnforceMemberQuota::class)))->toBeInstanceOf(ShouldQueue::class);

    Wallet::query()->whereKey($this->wallet->id)->update(['deleted_at' => now()]);
    WalletMember::query()->where('wallet_id', $this->wallet->id)->update(['deleted_at' => now()]);

    (new DemoteOnOwnershipTransfer(app(EnforceMemberQuota::class)))->handle(new WalletOwnershipTransferred('e', 't', $this->wallet->id, $this->owner->id, $this->editors[0]->id));
    (new DemoteOnLapse(app(EnforceMemberQuota::class)))->handle(new SubscriptionLapsed('e', 't', $this->owner->id, $this->subscription->id, 'expired'));
    app(EnforceMemberQuota::class)->forWallet((string) Str::uuid7());

    expect(WalletActivity::query()->count())->toBe(0);
});

test('the listeners are registered for the two events', function () {
    expect(Event::hasListeners(SubscriptionLapsed::class))->toBeTrue()
        ->and(Event::hasListeners(WalletOwnershipTransferred::class))->toBeTrue();
});
