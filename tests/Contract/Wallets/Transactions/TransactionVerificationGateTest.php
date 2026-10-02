<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * Every transaction write is behind the verification gate; reads are not
 * (spec 012, FR-021; security baseline C1, C2).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller('ada@example.test', verified: false);
    $this->wallet = ownedWallet($this->user->id);
    $this->tid = recordTransaction($this->user->id, $this->wallet->id)->transaction->id;
});

test('an unverified person is 403 EMAIL_NOT_VERIFIED on every write', function (string $write) {
    [$method, $body] = match ($write) {
        'put' => ['PUT', transactionBody()],
        'patch' => ['PATCH', ['amount_minor' => 1, 'version' => 1]],
        'restore' => ['PATCH', ['deleted_at' => null, 'version' => 1]],
        'delete' => ['DELETE', ['version' => 1]],
    };

    transactionCall($method, $this->credential, $this->wallet->id, $this->tid, $body)
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
})->with(['put', 'patch', 'restore', 'delete']);

test('the gate answers before the id is judged and nothing is created', function () {
    transactionCall('PUT', $this->credential, $this->wallet->id, 'not-an-id', transactionBody())->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    transactionCall('PUT', $this->credential, $this->wallet->id, (string) Str::uuid7(), transactionBody())->assertStatus(403);

    expect(Transaction::query()->count())->toBe(1);
});

test('reads work for an unverified person', function () {
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions')->assertOk();
    transactionCall('GET', $this->credential, $this->wallet->id, $this->tid)->assertOk();
});
