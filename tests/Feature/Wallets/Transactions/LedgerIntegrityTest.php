<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use Illuminate\Support\Str;

/*
 * Ledger integrity (spec 012 section 4; P-04 rules 1-3): after every
 * scenario a scan finds exactly two postings per live transaction, each
 * summing to zero with one target each, and none for a tombstone.
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 10000, 'balance_minor' => 10000]);
    $this->other = ownedWallet($this->owner->id);
    $this->service = app(TransactionService::class);
});

test('every kind posts two lines summing to zero', function () {
    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), transactionBody());
    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), incomeBody());
    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), transferBody($this->other->id));

    expect(Posting::query()->count())->toBe(6);
    expectLedgerIntact();
});

test('an edit replaces the postings wholesale and a delete removes them', function () {
    $id = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->wallet->id, $id, transactionBody(['amount_minor' => 500]));
    $before = Posting::query()->where('transaction_id', $id)->pluck('id')->all();

    $this->service->patch($this->owner->id, $this->wallet->id, $id, ['amount_minor' => 700], 1, presentTransaction());
    $after = Posting::query()->where('transaction_id', $id)->pluck('id')->all();

    expect(array_intersect($before, $after))->toBe([])->and($after)->toHaveCount(2);
    expectLedgerIntact();

    $this->service->delete($this->owner->id, $this->wallet->id, $id, 2, presentTransaction());
    expect(Posting::query()->where('transaction_id', $id)->count())->toBe(0);
    expectLedgerIntact();
});

test('a restore re-derives the two postings', function () {
    $id = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->wallet->id, $id, transactionBody());
    $this->service->delete($this->owner->id, $this->wallet->id, $id, 1, presentTransaction());
    $this->service->restore($this->owner->id, $this->wallet->id, $id, 2, presentTransaction());

    expect(Posting::query()->where('transaction_id', $id)->count())->toBe(2);
    expectLedgerIntact();
});

test('a transfer posts to both wallets and no category', function () {
    $id = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->wallet->id, $id, transferBody($this->other->id, ['amount_minor' => 250]));

    $postings = Posting::query()->where('transaction_id', $id)->get();
    expect($postings->pluck('category_id')->filter()->all())->toBe([])
        ->and($postings->where('wallet_id', $this->wallet->id)->sole()->amount_minor)->toBe(-250)
        ->and($postings->where('wallet_id', $this->other->id)->sole()->amount_minor)->toBe(250);
});
