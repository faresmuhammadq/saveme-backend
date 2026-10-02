<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;

/*
 * GET /sync/wallets/{id}/changes (spec 013, US1).
 */

beforeEach(function () {
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    [$this->grace, $this->graceCredential] = walletCaller('grace@example.test');
    $this->wallet = ownedWallet($this->ada->id);
    addMember($this->wallet->id, $this->grace->id, 'editor');
    $this->category = ownedCategory($this->ada->id, ['name' => 'Tea']);
});

test('a full read returns the wallet, its members, category references, transactions and transfers in, tombstones included', function () {
    $other = ownedWallet($this->ada->id);
    $expense = recordTransaction($this->ada->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    $gone = recordTransaction($this->ada->id, $this->wallet->id);
    app(TransactionService::class)->delete($this->ada->id, $this->wallet->id, $gone->transaction->id, 1, presentTransaction());
    $transfer = recordTransaction($this->ada->id, $other->id, transferBody($this->wallet->id));

    $rows = readFeedFully($this->adaCredential, $this->wallet->id)['rows'];
    $keys = feedKeys($rows);

    expect(collect($rows)->pluck('type')->unique()->sort()->values()->all())->toBe(['category_reference', 'transaction', 'wallet', 'wallet_member'])
        ->and($keys)->toContain('wallet:'.$this->wallet->id, 'transaction:'.$expense->transaction->id, 'transaction:'.$gone->transaction->id, 'transaction:'.$transfer->transaction->id)
        ->and(collect($rows)->where('record.id', $gone->transaction->id)->first()['record']['deleted_at'])->not->toBeNull()
        ->and(collect($rows)->where('type', 'wallet_member'))->toHaveCount(2)
        ->and(array_unique($keys))->toHaveCount(count($keys));
});

test('an incremental read from sync_cursor returns exactly the new writes, and the wallet record again', function () {
    recordTransaction($this->ada->id, $this->wallet->id);
    $cursor = readFeedFully($this->adaCredential, $this->wallet->id)['cursor'];

    $new = recordTransaction($this->grace->id, $this->wallet->id);

    $rows = readFeedFully($this->adaCredential, $this->wallet->id, $cursor)['rows'];
    expect(feedKeys($rows))->toBe(['transaction:'.$new->transaction->id, 'wallet:'.$this->wallet->id]);
});

test('my_role is the reader\'s', function () {
    $asAda = collect(readFeedFully($this->adaCredential, $this->wallet->id)['rows'])->firstWhere('type', 'wallet');
    $asGrace = collect(readFeedFully($this->graceCredential, $this->wallet->id)['rows'])->firstWhere('type', 'wallet');

    expect($asAda['record']['my_role'])->toBe('owner')->and($asGrace['record']['my_role'])->toBe('editor');
});

test('paging with limit=2 walks every row exactly once, in order', function () {
    foreach (range(1, 5) as $n) {
        recordTransaction($this->ada->id, $this->wallet->id);
    }

    $whole = readFeedFully($this->adaCredential, $this->wallet->id, limit: 200)['rows'];
    $paged = readFeedFully($this->adaCredential, $this->wallet->id, limit: 2)['rows'];

    expect(feedKeys($paged))->toBe(feedKeys($whole));
});

test('a deleted, unpurged wallet\'s feed is readable by those who were members at deletion and 404 to anyone else', function () {
    $stranger = person('hopper@example.test');
    $strangerCredential = signInAs('hopper@example.test')->json('data.credential');
    $former = person('lovelace@example.test');
    $formerCredential = signInAs('lovelace@example.test')->json('data.credential');
    $old = addMember($this->wallet->id, $former->id, 'viewer');
    $old->forceFill(['deleted_at' => now()->subDay()])->save();

    walletCall('DELETE', $this->adaCredential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();

    $rows = readFeedFully($this->graceCredential, $this->wallet->id)['rows'];
    expect(collect($rows)->firstWhere('type', 'wallet')['record']['deleted_at'])->not->toBeNull();

    readFeed($strangerCredential, $this->wallet->id)->assertNotFound();
    readFeed($formerCredential, $this->wallet->id)->assertNotFound();
});

test('a feed carries no postings', function () {
    recordTransaction($this->ada->id, $this->wallet->id);

    $json = json_encode(readFeedFully($this->adaCredential, $this->wallet->id)['rows']);

    expect($json)->not->toContain('postings')->and($json)->not->toContain('counter_change_seq');
});
