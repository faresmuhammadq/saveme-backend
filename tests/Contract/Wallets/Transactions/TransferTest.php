<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * A transfer over HTTP (spec 012, US2; ADR-0015): one record, readable and
 * editable under either wallet's path.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->source = ownedWallet($this->user->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->destination = ownedWallet($this->user->id, ['opening_balance_minor' => 0, 'balance_minor' => 0]);
    $this->id = (string) Str::uuid7();
});

test('creating a transfer is 201 with one record and both balances moved', function () {
    $response = transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($this->destination->id, ['amount_minor' => 7000]))
        ->assertValidRequest()
        ->assertValidResponse(201);

    expect($response->json('data'))->toMatchArray([
        'kind' => 'transfer', 'wallet_id' => $this->source->id, 'counter_wallet_id' => $this->destination->id, 'category_id' => null, 'amount_minor' => 7000,
    ])->and(Transaction::query()->count())->toBe(1)
        ->and(walletBalance($this->source->id))->toBe(43000)
        ->and(walletBalance($this->destination->id))->toBe(7000);
});

test('the identical record is read under both wallets', function () {
    transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($this->destination->id))->assertCreated();

    $fromSource = transactionCall('GET', $this->credential, $this->source->id, $this->id)->assertValidResponse(200)->json('data');
    $fromDestination = transactionCall('GET', $this->credential, $this->destination->id, $this->id)->assertValidResponse(200)->json('data');

    expect($fromDestination)->toBe($fromSource);
});

test('an edit under the destination\'s path is 200 and the response is the one record', function () {
    transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($this->destination->id, ['amount_minor' => 7000]))->assertCreated();

    transactionCall('PATCH', $this->credential, $this->destination->id, $this->id, ['amount_minor' => 2000, 'version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.wallet_id', $this->source->id)
        ->assertJsonPath('data.amount_minor', 2000);

    expect(walletBalance($this->source->id))->toBe(48000)->and(walletBalance($this->destination->id))->toBe(2000);
});

test('a whole save under the destination\'s path works too', function () {
    transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($this->destination->id, ['amount_minor' => 7000]))->assertCreated();

    transactionCall('PUT', $this->credential, $this->destination->id, $this->id, transferBody($this->destination->id, ['amount_minor' => 1000], version: 1))
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);
});

test('a destination change is 422 IMMUTABLE_FIELD at /counter_wallet_id', function () {
    $third = ownedWallet($this->user->id);
    transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($this->destination->id))->assertCreated();

    $response = transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($third->id, version: 1))->assertValidResponse(422);

    expect($response->json('errors'))->toBe([['pointer' => '/counter_wallet_id', 'code' => 'IMMUTABLE_FIELD', 'detail' => 'This field cannot be changed.']]);
});

test('the refusals carry valid problem bodies', function (string $case, int $status, string $pointer, string $code) {
    $stranger = person('grace@example.test');
    $other = match ($case) {
        'cross-currency' => ownedWallet($this->user->id, ['currency' => 'USD'])->id,
        'not a member' => ownedWallet($stranger->id)->id,
        'the same wallet' => $this->source->id,
        'viewer' => (function () use ($stranger): string {
            $theirs = ownedWallet($stranger->id);
            addMember($theirs->id, $this->user->id, 'viewer');

            return $theirs->id;
        })(),
    };

    $response = transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($other))->assertValidResponse($status);

    if ($status === 422) {
        expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'])->toBe($code);
    } else {
        $response->assertJsonPath('code', $code);
    }
    expect(Transaction::query()->count())->toBe(0);
})->with([
    'cross-currency' => ['cross-currency', 422, '/counter_wallet_id', 'CURRENCY_MISMATCH'],
    'not a member' => ['not a member', 422, '/counter_wallet_id', 'INVALID_ENUM_VALUE'],
    'the same wallet' => ['the same wallet', 422, '/counter_wallet_id', 'INVALID_ENUM_VALUE'],
    'viewer' => ['viewer', 403, '', 'FORBIDDEN'],
]);

test('a transfer is not found under a third wallet', function () {
    $third = ownedWallet($this->user->id);
    transactionCall('PUT', $this->credential, $this->source->id, $this->id, transferBody($this->destination->id))->assertCreated();

    transactionCall('GET', $this->credential, $third->id, $this->id)->assertValidResponse(404);
    transactionCall('PATCH', $this->credential, $third->id, $this->id, ['amount_minor' => 1, 'version' => 1])->assertValidResponse(404);
});
