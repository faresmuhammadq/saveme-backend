<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;

/*
 * A transfer rides both of its wallets' feeds (spec 013, US1; ADR-0015).
 */

beforeEach(function () {
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    [$this->grace, $this->graceCredential] = walletCaller('grace@example.test');
    $this->source = ownedWallet($this->ada->id);
    $this->destination = ownedWallet($this->grace->id);
    $this->third = ownedWallet($this->ada->id);
    addMember($this->destination->id, $this->ada->id, 'editor');
    $this->transfer = recordTransaction($this->ada->id, $this->source->id, transferBody($this->destination->id))->transaction;
});

function transferRow(string $credential, string $walletId, string $transactionId): ?array
{
    return collect(readFeedFully($credential, $walletId)['rows'])->firstWhere('record.id', $transactionId);
}

test('it appears on both feeds as the identical record, and on no third wallet\'s feed', function () {
    $onSource = transferRow($this->adaCredential, $this->source->id, $this->transfer->id);
    $onDestination = transferRow($this->graceCredential, $this->destination->id, $this->transfer->id);

    expect($onSource)->not->toBeNull()->and($onDestination)->toBe($onSource)
        ->and(transferRow($this->adaCredential, $this->third->id, $this->transfer->id))->toBeNull();
});

test('a member of the destination who is not a member of the source can read it', function () {
    expect(transferRow($this->graceCredential, $this->destination->id, $this->transfer->id))->not->toBeNull();
    readFeed($this->graceCredential, $this->source->id)->assertNotFound();
});

test('editing it re-delivers it on both feeds', function () {
    $sourceCursor = readFeedFully($this->adaCredential, $this->source->id)['cursor'];
    $destinationCursor = readFeedFully($this->graceCredential, $this->destination->id)['cursor'];

    saveTransaction($this->ada->id, $this->source->id, $this->transfer->id, transferBody($this->destination->id, ['description' => 'Edited'], 1));

    $onSource = collect(readFeedFully($this->adaCredential, $this->source->id, $sourceCursor)['rows'])->firstWhere('record.id', $this->transfer->id);
    $onDestination = collect(readFeedFully($this->graceCredential, $this->destination->id, $destinationCursor)['rows'])->firstWhere('record.id', $this->transfer->id);
    expect($onSource['record']['description'])->toBe('Edited')->and($onDestination)->toBe($onSource);
});

test('deleting it re-delivers the tombstone on both feeds', function () {
    $destinationCursor = readFeedFully($this->graceCredential, $this->destination->id)['cursor'];

    app(TransactionService::class)->delete($this->ada->id, $this->source->id, $this->transfer->id, 1, presentTransaction());

    $row = collect(readFeedFully($this->graceCredential, $this->destination->id, $destinationCursor)['rows'])->firstWhere('record.id', $this->transfer->id);
    expect($row['record']['deleted_at'])->not->toBeNull();
});
