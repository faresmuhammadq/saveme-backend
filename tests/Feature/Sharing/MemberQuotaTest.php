<?php

use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\Queue;

/*
 * The member quota invariants (spec 016, FR-015, FR-022; P-09; research R-7):
 * owner + active members + live pending invitations never exceed the owner's
 * `sharing.max_members_per_wallet` through an invitation or an acceptance, and
 * a promotion to editor is refused without sharing or beyond the quota.
 */

beforeEach(function () {
    Queue::fake();
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
});

/**
 * Owner + active members + live pending invitations of the wallet.
 */
function seatsInUse(string $walletId): int
{
    return WalletMember::query()->where('wallet_id', $walletId)->whereNull('deleted_at')->count()
        + Invitation::query()->where('wallet_id', $walletId)->whereNull('deleted_at')->where('expires_at', '>', now())->count();
}

test('no sequence of invitations, acceptances and revocations takes the wallet past the quota', function () {
    $invitees = [];
    foreach (range(1, 7) as $n) {
        $person = person("invitee{$n}@example.test");
        $invitees[] = signInAs("invitee{$n}@example.test")->json('data.credential');
    }

    $open = [];
    foreach (range(1, 14) as $step) {
        $action = $step % 4;

        if ($action === 1 || $action === 2) {
            $response = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => null]);
            if ($response->getStatusCode() === 201) {
                $open[] = [$response->json('data.id'), tokenOf($response->json('meta.share_url'))];
            }
        } elseif ($action === 3 && $open !== []) {
            [$id, $token] = array_shift($open);
            respondToInvitation($invitees[$step % 7], 'accept', $token);
        } elseif ($open !== []) {
            [$id] = array_pop($open);
            walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$id, ['version' => 1]);
        }

        expect(seatsInUse($this->wallet->id))->toBeLessThanOrEqual(5);
    }
});

test('an invitation is refused exactly when it would take the count past the quota, and a revoke or expiry frees the seat', function () {
    foreach (range(1, 4) as $n) {
        sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => "p{$n}@example.test"])->assertCreated();
    }
    expect(seatsInUse($this->wallet->id))->toBe(5);
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'late@example.test'])->assertForbidden();

    // An expired invitation no longer counts, even before the sweep closes it.
    Invitation::query()->where('wallet_id', $this->wallet->id)->limit(1)->update(['expires_at' => now()->subSecond()]);
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'late@example.test'])->assertCreated();
    expect(seatsInUse($this->wallet->id))->toBe(5);
});

test('a plan with no member limit is never refused for the count', function () {
    $plan = Plan::factory()->create(['features' => ['sharing.enabled' => true, 'sharing.max_members_per_wallet' => null, 'export.enabled' => true, 'reports.advanced' => true, 'wallets.max' => null]]);
    Subscription::query()->where('user_id', $this->owner->id)->update(['plan_id' => $plan->id]);
    app()->forgetScopedInstances();

    foreach (range(1, 8) as $n) {
        sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => null])->assertCreated();
    }

    expect(seatsInUse($this->wallet->id))->toBe(9);
});

test('promotion to editor is refused without sharing and beyond the quota, and allowed within it', function () {
    $viewer = person('viewer@example.test');
    $member = addMember($this->wallet->id, $viewer->id, 'viewer');
    $uri = '/wallets/'.$this->wallet->id.'/members/'.$viewer->id;

    walletCall('PATCH', $this->credential, $uri, ['role' => 'editor', 'version' => 1])->assertOk();
    walletCall('PATCH', $this->credential, $uri, ['role' => 'viewer', 'version' => 2])->assertOk();

    foreach (range(1, 4) as $n) {
        addMember($this->wallet->id, person("m{$n}@example.test")->id, 'viewer');
    }
    // Owner + 5 members = 6, above the quota of 5: re-promoting past a downgrade is refused.
    walletCall('PATCH', $this->credential, $uri, ['role' => 'editor', 'version' => 3])->assertForbidden()->assertJsonPath('feature', 'sharing.max_members_per_wallet');

    Subscription::query()->where('user_id', $this->owner->id)->delete();
    app()->forgetScopedInstances();
    walletCall('PATCH', $this->credential, $uri, ['role' => 'editor', 'version' => 3])->assertForbidden()->assertJsonPath('feature', 'sharing.enabled');
    expect(WalletMember::query()->findOrFail($member->id)->role)->toBe('viewer');
});
