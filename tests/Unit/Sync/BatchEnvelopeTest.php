<?php

use App\Modules\Sync\Domain\Batch\BatchEnvelope;
use App\Modules\Sync\Domain\Batch\ItemOperation;
use App\Modules\Sync\Domain\Batch\ItemType;
use App\Modules\Sync\Domain\Refusals\EnvelopeInvalid;

/*
 * The batch envelope rules (spec 013, R-4). Nothing inside `data` is judged.
 */

const BATCH_ID_A = '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70';
const BATCH_ID_B = '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f71';

/**
 * @return list<array{string, string}> pointer and code of every fault
 */
function envelopeFaults(array $body): array
{
    try {
        BatchEnvelope::parse($body);
    } catch (EnvelopeInvalid $e) {
        return array_map(fn ($error): array => [$error->pointer, $error->code->value], $e->fieldErrors);
    }

    return [];
}

function batchItem(array $changes = []): array
{
    $item = ['type' => 'wallet', 'op' => 'upsert', 'id' => BATCH_ID_A, 'data' => ['version' => 0]];

    foreach ($changes as $key => $value) {
        $item[$key] = $value;
    }

    return $item;
}

test('a valid envelope yields typed items in order', function () {
    $items = BatchEnvelope::parse(['items' => [
        batchItem(),
        batchItem(['type' => 'transaction', 'op' => 'restore', 'wallet_id' => BATCH_ID_B, 'id' => BATCH_ID_B, 'data' => ['version' => 2]]),
        batchItem(['type' => 'profile']),
    ]]);

    expect($items)->toHaveCount(3)
        ->and($items[1]->type)->toBe(ItemType::Transaction)
        ->and($items[1]->operation)->toBe(ItemOperation::Restore)
        ->and($items[1]->walletId)->toBe(BATCH_ID_B);
});

test('the items array is required, an array, and 1 to 50 long', function () {
    expect(envelopeFaults([]))->toBe([['/items', 'REQUIRED']])
        ->and(envelopeFaults(['items' => 'x']))->toBe([['/items', 'INVALID_TYPE']])
        ->and(envelopeFaults(['items' => ['a' => 1]]))->toBe([['/items', 'INVALID_TYPE']])
        ->and(envelopeFaults(['items' => []]))->toBe([['/items', 'OUT_OF_RANGE']])
        ->and(envelopeFaults(['items' => array_fill(0, 51, batchItem())]))->toBe([['/items', 'OUT_OF_RANGE']])
        ->and(envelopeFaults(['items' => array_fill(0, 50, batchItem())]))->toBe([]);
});

test('an unknown root member is UNKNOWN_FIELD', function () {
    expect(envelopeFaults(['items' => [batchItem()], 'extra' => 1]))->toBe([['/extra', 'UNKNOWN_FIELD']]);
});

test('an item must be an object', function () {
    expect(envelopeFaults(['items' => ['x']]))->toBe([['/items/0', 'INVALID_TYPE']])
        ->and(envelopeFaults(['items' => [[1, 2]]]))->toBe([['/items/0', 'INVALID_TYPE']]);
});

test('type, op, id and data are required and checked', function () {
    expect(envelopeFaults(['items' => [[]]]))->toBe([
        ['/items/0/type', 'REQUIRED'], ['/items/0/op', 'REQUIRED'], ['/items/0/id', 'REQUIRED'], ['/items/0/data', 'REQUIRED'],
    ])
        ->and(envelopeFaults(['items' => [batchItem(['type' => 'goal'])]]))->toBe([['/items/0/type', 'INVALID_ENUM_VALUE']])
        ->and(envelopeFaults(['items' => [batchItem(['op' => 'merge'])]]))->toBe([['/items/0/op', 'INVALID_ENUM_VALUE']])
        ->and(envelopeFaults(['items' => [batchItem(['id' => 'nope'])]]))->toBe([['/items/0/id', 'INVALID_ID']])
        ->and(envelopeFaults(['items' => [batchItem(['id' => '0192f7a0-1c3d-4abc-9f01-2b3c4d5e6f70'])]]))->toBe([['/items/0/id', 'INVALID_ID']])
        ->and(envelopeFaults(['items' => [batchItem(['data' => 'x'])]]))->toBe([['/items/0/data', 'INVALID_TYPE']])
        ->and(envelopeFaults(['items' => [batchItem(['data' => [1, 2]])]]))->toBe([['/items/0/data', 'INVALID_TYPE']])
        ->and(envelopeFaults(['items' => [batchItem(['data' => []])]]))->toBe([]);
});

test('an op the type does not allow is INVALID_ENUM_VALUE', function (string $type, string $op) {
    expect(envelopeFaults(['items' => [batchItem(['type' => $type, 'op' => $op, 'wallet_id' => BATCH_ID_B])]]))->toContain(['/items/0/op', 'INVALID_ENUM_VALUE']);
})->with([['wallet', 'restore'], ['category', 'restore'], ['profile', 'delete'], ['profile', 'restore']]);

test('wallet_id is required on a transaction and refused elsewhere', function () {
    expect(envelopeFaults(['items' => [batchItem(['type' => 'transaction'])]]))->toBe([['/items/0/wallet_id', 'REQUIRED']])
        ->and(envelopeFaults(['items' => [batchItem(['type' => 'transaction', 'wallet_id' => 'x'])]]))->toBe([['/items/0/wallet_id', 'INVALID_ID']])
        ->and(envelopeFaults(['items' => [batchItem(['wallet_id' => BATCH_ID_B])]]))->toBe([['/items/0/wallet_id', 'UNKNOWN_FIELD']]);
});

test('an unknown item member is UNKNOWN_FIELD and pointers escape ~ and /', function () {
    expect(envelopeFaults(['items' => [batchItem(['extra' => 1])]]))->toBe([['/items/0/extra', 'UNKNOWN_FIELD']])
        ->and(envelopeFaults(['a/b~c' => 1, 'items' => [batchItem()]]))->toBe([['/a~1b~0c', 'UNKNOWN_FIELD']]);
});
