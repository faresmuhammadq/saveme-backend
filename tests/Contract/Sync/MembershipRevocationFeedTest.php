<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Sharing\Application\EnforceMemberQuota;

/*
 * The purge signal (spec 016, ADR-0017 rule 4; C9): when a membership ends by
 * removal or by leaving, the person's own user feed carries the tombstoned
 * `wallet_member`, and after it every request of theirs on that wallet is
 * 404. A role change re-sends the live row on both feeds.
 */

beforeEach(function () {
    [$this->owner, $this->ownerCredential, $this->wallet] = plusOwner();
    $this->karim = person('karim@example.test');
    addMember($this->wallet->id, $this->karim->id, 'editor');
    $this->karimCredential = signInAs('karim@example.test')->json('data.credential');
    $this->members = '/wallets/'.$this->wallet->id.'/members';
});

function ownMembershipRow(string $credential, string $walletId, ?string $cursor = null): ?array
{
    $rows = collect(readFeedFully($credential, 'user', $cursor)['rows'])->where('type', 'wallet_member')->where('record.wallet_id', $walletId);

    return $rows->last()['record'] ?? null;
}

test('removal: the removed person\'s user feed carries the tombstone, then every wallet path is 404', function () {
    $cursor = readFeedFully($this->karimCredential, 'user')['cursor'];

    walletCall('DELETE', $this->ownerCredential, $this->members.'/'.$this->karim->id, ['version' => 1])->assertOk();

    $row = ownMembershipRow($this->karimCredential, $this->wallet->id, $cursor);
    expect($row)->toMatchArray(['member_user_id' => $this->karim->id, 'version' => 2])
        ->and($row['deleted_at'])->not->toBeNull();

    foreach (['', '/members', '/transactions', '/invitations', '/activity', '/recurring-rules'] as $path) {
        walletCall('GET', $this->karimCredential, '/wallets/'.$this->wallet->id.$path)->assertNotFound();
    }
    readFeed($this->karimCredential, $this->wallet->id)->assertNotFound();
});

test('leaving: the same signal, and a repeated leave is 404', function () {
    $cursor = readFeedFully($this->karimCredential, 'user')['cursor'];

    walletCall('POST', $this->karimCredential, '/wallets/'.$this->wallet->id.'/leave', ['version' => 1])->assertOk();

    expect(ownMembershipRow($this->karimCredential, $this->wallet->id, $cursor)['deleted_at'])->not->toBeNull();
    readFeed($this->karimCredential, $this->wallet->id)->assertNotFound();
    walletCall('POST', $this->karimCredential, '/wallets/'.$this->wallet->id.'/leave', ['version' => 2])->assertNotFound();
});

test('the other members see the tombstone on the wallet feed, and nobody sees it on another person\'s user feed', function () {
    $other = person('other@example.test');
    addMember($this->wallet->id, $other->id, 'viewer');
    $otherCredential = signInAs('other@example.test')->json('data.credential');

    walletCall('DELETE', $this->ownerCredential, $this->members.'/'.$this->karim->id, ['version' => 1])->assertOk();

    $walletRow = collect(readFeedFully($otherCredential, $this->wallet->id)['rows'])->where('type', 'wallet_member')->where('record.member_user_id', $this->karim->id)->first();
    expect($walletRow['record']['deleted_at'])->not->toBeNull()
        ->and(collect(readFeedFully($otherCredential, 'user')['rows'])->where('record.member_user_id', $this->karim->id)->count())->toBe(0);
});

test('a role change re-sends the live row on the member\'s user feed and on the wallet feed, identical', function () {
    $userCursor = readFeedFully($this->karimCredential, 'user')['cursor'];
    $walletCursor = readFeedFully($this->ownerCredential, $this->wallet->id)['cursor'];

    walletCall('PATCH', $this->ownerCredential, $this->members.'/'.$this->karim->id, ['role' => 'viewer', 'version' => 1])->assertOk();

    $own = ownMembershipRow($this->karimCredential, $this->wallet->id, $userCursor);
    $onWallet = collect(readFeedFully($this->ownerCredential, $this->wallet->id, $walletCursor)['rows'])->where('type', 'wallet_member')->where('record.member_user_id', $this->karim->id)->first();

    expect($own)->toMatchArray(['role' => 'viewer', 'version' => 2, 'deleted_at' => null])
        ->and($onWallet['record'])->toBe($own);
});

test('plan demotion re-sends the live row too, without removing anyone', function () {
    $userCursor = readFeedFully($this->karimCredential, 'user')['cursor'];
    Subscription::query()->where('user_id', $this->owner->id)->update(['status' => 'expired', 'current_period_end' => now()->subDay()]);
    app()->forgetScopedInstances();

    app(EnforceMemberQuota::class)->forOwner($this->owner->id);

    $own = ownMembershipRow($this->karimCredential, $this->wallet->id, $userCursor);
    expect($own)->toMatchArray(['role' => 'viewer', 'deleted_at' => null]);
    walletCall('GET', $this->karimCredential, '/wallets/'.$this->wallet->id)->assertOk();
});
