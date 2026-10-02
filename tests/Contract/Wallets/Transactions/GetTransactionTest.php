<?php

use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/transactions/{tid} (spec 012, US4).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->other = ownedWallet($this->user->id);
    $this->id = recordTransaction($this->user->id, $this->wallet->id)->transaction->id;
});

test('a live transaction is read with every member', function () {
    $response = transactionCall('GET', $this->credential, $this->wallet->id, $this->id)->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray(['id' => $this->id, 'wallet_id' => $this->wallet->id, 'deleted_at' => null, 'version' => 1]);
    $response->assertHeader('X-Request-Id');
});

test('a tombstone is read by a member with deleted_at set', function () {
    transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 1])->assertOk();

    transactionCall('GET', $this->credential, $this->wallet->id, $this->id)
        ->assertValidResponse(200)
        ->assertJsonPath('data.deleted_at', fn ($at) => $at !== null);
});

test('every member reads: owner, editor and viewer', function () {
    $editor = person('grace@example.test');
    $viewer = person('linus@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');
    addMember($this->wallet->id, $viewer->id, 'viewer');

    foreach (['grace@example.test', 'linus@example.test'] as $email) {
        $credential = signInAs($email)->json('data.credential');
        transactionCall('GET', $credential, $this->wallet->id, $this->id)->assertValidResponse(200);
    }
});

test('a transfer is read under both paths and a plain transaction only under its own', function () {
    $transfer = recordTransaction($this->user->id, $this->wallet->id, transferBody($this->other->id))->transaction->id;

    transactionCall('GET', $this->credential, $this->wallet->id, $transfer)->assertValidResponse(200);
    transactionCall('GET', $this->credential, $this->other->id, $transfer)->assertValidResponse(200);
    transactionCall('GET', $this->credential, $this->other->id, $this->id)->assertValidResponse(404);
});

test('404 for another wallet\'s transaction, another kind of record, an unknown id, a non-member and a deleted path wallet', function () {
    $stranger = person('grace@example.test');
    $theirs = ownedWallet($stranger->id);
    $foreign = recordTransaction($stranger->id, $theirs->id)->transaction->id;
    $category = ownedCategory($this->user->id);

    transactionCall('GET', $this->credential, $this->wallet->id, $foreign)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    transactionCall('GET', $this->credential, $theirs->id, $foreign)->assertValidResponse(404);
    transactionCall('GET', $this->credential, $this->wallet->id, $this->wallet->id)->assertValidResponse(404);
    transactionCall('GET', $this->credential, $this->wallet->id, $category->id)->assertValidResponse(404);
    transactionCall('GET', $this->credential, $this->wallet->id, (string) Str::uuid7())->assertValidResponse(404);

    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();
    transactionCall('GET', $this->credential, $this->wallet->id, $this->id)->assertValidResponse(404);
});

test('a malformed id is 422 INVALID_ID and a credential is needed', function () {
    transactionCall('GET', $this->credential, $this->wallet->id, 'nope')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/wallets/'.$this->wallet->id.'/transactions/'.$this->id)->assertValidResponse(401);
});
