<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * A membership rides its member's user feed (spec 013, US3; ADR-0017).
 */

beforeEach(function () {
    // The plan-demotion listener (spec 016) is queued; these tests are about the transfer itself, and the demotion has its own tests.
    Queue::fake();
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    [$this->grace, $this->graceCredential] = walletCaller('grace@example.test');
    $this->walletId = (string) Str::uuid7();
});

function membershipKeys(string $credential, ?string $cursor = null): array
{
    return collect(readFeedFully($credential, 'user', $cursor)['rows'])->where('type', 'wallet_member')->pluck('record.id')->all();
}

test('creating a wallet puts the owner\'s membership on their user feed', function () {
    $cursor = readFeedFully($this->adaCredential, 'user')['cursor'];

    walletCall('PUT', $this->adaCredential, '/wallets/'.$this->walletId, walletBody())->assertCreated();

    $rows = collect(readFeedFully($this->adaCredential, 'user', $cursor)['rows']);
    expect($rows->pluck('type')->all())->toBe(['wallet_member'])
        ->and($rows[0]['record']['wallet_id'])->toBe($this->walletId)
        ->and($rows[0]['record']['role'])->toBe('owner');
});

test('an ownership transfer puts both changed memberships on each member\'s own feed only', function () {
    walletCall('PUT', $this->adaCredential, '/wallets/'.$this->walletId, walletBody())->assertCreated();
    addMember($this->walletId, $this->grace->id, 'editor');
    $adaCursor = readFeedFully($this->adaCredential, 'user')['cursor'];
    $graceCursor = readFeedFully($this->graceCredential, 'user')['cursor'];

    walletCall('POST', $this->adaCredential, '/wallets/'.$this->walletId.'/transfer-ownership', ['user_id' => $this->grace->id, 'version' => 1])->assertOk();

    $ada = collect(readFeedFully($this->adaCredential, 'user', $adaCursor)['rows'])->where('type', 'wallet_member');
    $grace = collect(readFeedFully($this->graceCredential, 'user', $graceCursor)['rows'])->where('type', 'wallet_member');
    expect($ada)->toHaveCount(1)->and($ada->first()['record']['member_user_id'])->toBe($this->ada->id)->and($ada->first()['record']['role'])->toBe('editor')
        ->and($grace)->toHaveCount(1)->and($grace->first()['record']['member_user_id'])->toBe($this->grace->id)->and($grace->first()['record']['role'])->toBe('owner');
});

test('deleting a wallet tombstones each member\'s membership on that member\'s own feed', function () {
    walletCall('PUT', $this->adaCredential, '/wallets/'.$this->walletId, walletBody())->assertCreated();
    addMember($this->walletId, $this->grace->id, 'viewer');
    $adaCursor = readFeedFully($this->adaCredential, 'user')['cursor'];
    $graceCursor = readFeedFully($this->graceCredential, 'user')['cursor'];

    walletCall('DELETE', $this->adaCredential, '/wallets/'.$this->walletId, ['version' => 1])->assertOk();

    $ada = collect(readFeedFully($this->adaCredential, 'user', $adaCursor)['rows'])->where('type', 'wallet_member');
    $grace = collect(readFeedFully($this->graceCredential, 'user', $graceCursor)['rows'])->where('type', 'wallet_member');
    expect($ada->pluck('record.member_user_id')->all())->toBe([$this->ada->id])
        ->and($grace->pluck('record.member_user_id')->all())->toBe([$this->grace->id])
        ->and($ada->first()['record']['deleted_at'])->not->toBeNull()
        ->and($grace->first()['record']['deleted_at'])->not->toBeNull();
});

test('another member\'s membership never appears on a person\'s user feed', function () {
    walletCall('PUT', $this->adaCredential, '/wallets/'.$this->walletId, walletBody())->assertCreated();
    $member = addMember($this->walletId, $this->grace->id, 'viewer');

    expect(membershipKeys($this->adaCredential))->not->toContain($member->id)
        ->and(membershipKeys($this->graceCredential))->toBe([$member->id]);
});

test('the row equals the wallet feed\'s row for the same membership', function () {
    walletCall('PUT', $this->adaCredential, '/wallets/'.$this->walletId, walletBody())->assertCreated();

    $onUser = collect(readFeedFully($this->adaCredential, 'user')['rows'])->firstWhere('type', 'wallet_member');
    $onWallet = collect(readFeedFully($this->adaCredential, $this->walletId)['rows'])->firstWhere('type', 'wallet_member');

    expect($onUser)->toBe($onWallet)->and($onUser['record'])->not->toHaveKey('member_change_seq');
});

test('membership stamps on a person\'s feed are above the counter they started from and unique', function () {
    walletCall('PUT', $this->adaCredential, '/wallets/'.$this->walletId, walletBody())->assertCreated();
    walletCall('PUT', $this->adaCredential, '/wallets/'.(string) Str::uuid7(), walletBody())->assertCreated();

    $stamps = DB::table('wallet_members')->where('user_id', $this->ada->id)->pluck('member_change_seq')->all();

    expect(array_unique($stamps))->toHaveCount(2)
        ->and(max($stamps))->toBeLessThanOrEqual((int) DB::table('users')->where('id', $this->ada->id)->value('change_seq'));
});
