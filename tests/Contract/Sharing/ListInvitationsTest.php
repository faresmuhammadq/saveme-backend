<?php

use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Testing\TestResponse;

/*
 * GET /wallets/{id}/invitations and GET /wallets/{id}/invitations/{iid} (spec
 * 016, US3; row S-01): every documented status.
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
});

function listInvitations(string $credential, string $walletId, string $query = ''): TestResponse
{
    return walletCall('GET', $credential, '/wallets/'.$walletId.'/invitations'.$query);
}

test('200 lists the pending invitations, oldest first, closed ones never', function () {
    $first = pendingInvitation($this->owner->id, $this->wallet->id, 'a@example.test');
    $second = pendingInvitation($this->owner->id, $this->wallet->id, null, 'viewer');
    $closed = pendingInvitation($this->owner->id, $this->wallet->id, 'c@example.test');
    Invitation::query()->whereKey($closed['id'])->update(['status' => 'declined', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]);

    $response = listInvitations($this->credential, $this->wallet->id)->assertValidRequest()->assertValidResponse(200);

    expect(array_column($response->json('data'), 'id'))->toBe([$first['id'], $second['id']])
        ->and($response->json('data.0.invitee_email'))->toBe('a@example.test')
        ->and($response->json('data.1.invitee_email'))->toBeNull()
        ->and($response->json('meta'))->toMatchArray(['next_cursor' => null, 'has_more' => false, 'limit' => 50]);
});

test('an empty wallet is 200 with no data; every member may read, a non-member may not', function () {
    expect(listInvitations($this->credential, $this->wallet->id)->assertValidResponse(200)->json('data'))->toBe([]);

    pendingInvitation($this->owner->id, $this->wallet->id, 'a@example.test');
    foreach (['editor', 'viewer'] as $role) {
        addMember($this->wallet->id, person("{$role}@example.test")->id, $role);
        $credential = signInAs("{$role}@example.test")->json('data.credential');
        expect(listInvitations($credential, $this->wallet->id)->assertValidResponse(200)->json('data'))->toHaveCount(1);
    }

    [, $stranger] = walletCaller('stranger@example.test');
    listInvitations($stranger, $this->wallet->id)->assertValidResponse(404);
});

test('paging with limit visits every pending invitation once', function () {
    $ids = [];
    foreach (range(1, 3) as $n) {
        $ids[] = pendingInvitation($this->owner->id, $this->wallet->id, "p{$n}@example.test")['id'];
    }

    $first = listInvitations($this->credential, $this->wallet->id, '?limit=2')->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue();
    $second = listInvitations($this->credential, $this->wallet->id, '?limit=2&cursor='.$first->json('meta.next_cursor'))->assertValidResponse(200);

    expect([...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')])->toBe($ids)
        ->and($second->json('meta.has_more'))->toBeFalse();
});

test('400 for a filter, a sort or a forged cursor', function () {
    withoutSpectatorRequestValidation();

    listInvitations($this->credential, $this->wallet->id, '?filter[status]=pending')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    listInvitations($this->credential, $this->wallet->id, '?sort=role')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    listInvitations($this->credential, $this->wallet->id, '?cursor=forged')->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('422 INVALID_ID for a malformed wallet id', function () {
    listInvitations($this->credential, 'not-an-id')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('the shared refusals on the list: unauthenticated, not acceptable, upgrade required', function () {
    assertSharedRefusals('GET', '/wallets/'.$this->wallet->id.'/invitations', [], $this->credential);
});

test('GET one: 200 for a pending invitation and for a tombstone', function () {
    $pending = pendingInvitation($this->owner->id, $this->wallet->id, 'a@example.test');
    $closed = pendingInvitation($this->owner->id, $this->wallet->id, 'b@example.test');
    Invitation::query()->whereKey($closed['id'])->update(['status' => 'accepted', 'deleted_at' => now(), 'version' => 2, 'address_sealed' => null, 'address_lookup' => null]);

    $live = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$pending['id'])->assertValidRequest()->assertValidResponse(200);
    $dead = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$closed['id'])->assertValidResponse(200);

    expect($live->json('data'))->toMatchArray(['id' => $pending['id'], 'status' => 'pending', 'invitee_email' => 'a@example.test'])
        ->and($dead->json('data'))->toMatchArray(['id' => $closed['id'], 'status' => 'accepted', 'invitee_email' => null])
        ->and($dead->json('data.deleted_at'))->not->toBeNull()
        ->and($live->json('meta'))->toBe([]);
});

test('GET one: 404 for an unknown id, another wallet\'s invitation, another kind of record and a non-member', function () {
    $other = ownedWallet($this->owner->id);
    $foreign = pendingInvitation($this->owner->id, $other->id, null);
    $transaction = Transaction::factory()->create(['wallet_id' => $this->wallet->id]);

    foreach ([(string) Str::uuid7(), $foreign['id'], $transaction->id, $this->wallet->id] as $id) {
        walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$id)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    }

    $mine = pendingInvitation($this->owner->id, $this->wallet->id, null);
    [, $stranger] = walletCaller('stranger@example.test');
    walletCall('GET', $stranger, '/wallets/'.$this->wallet->id.'/invitations/'.$mine['id'])->assertValidResponse(404);
});

test('GET one: 422 for malformed ids and the shared refusals', function () {
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/not-an-id')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    $mine = pendingInvitation($this->owner->id, $this->wallet->id, null);

    assertSharedRefusals('GET', '/wallets/'.$this->wallet->id.'/invitations/'.$mine['id'], [], $this->credential);
});
