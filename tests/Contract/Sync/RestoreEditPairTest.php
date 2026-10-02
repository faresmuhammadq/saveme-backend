<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;

/*
 * Restore with an edit (spec 017, US3; docs/openapi/paths/sync.yaml, "Restore
 * with an edit"): a restore at version n answers n + 1, so an upsert at
 * n + 1 in the same batch applies; a restore that did not apply refuses the
 * upsert whole. No production code: the written promise is the test.
 */

/**
 * A deleted transaction at version 2 in a wallet the caller owns (or reads, as a viewer).
 *
 * @return array{string, string, string} credential, wallet id, transaction id
 */
function deletedTransaction(string $role = 'owner'): array
{
    [$user, $credential] = walletCaller($role === 'owner' ? 'ada@example.test' : 'viewer@example.test');
    $owner = $role === 'owner' ? $user : person('owner@example.test');
    $wallet = ownedWallet($owner->id);

    if ($role !== 'owner') {
        addMember($wallet->id, $user->id, $role);
    }

    $transaction = recordTransaction($owner->id, $wallet->id)->transaction;
    app(TransactionService::class)->delete($owner->id, $wallet->id, $transaction->id, 1, presentTransaction());

    return [$credential, $wallet->id, $transaction->id];
}

function restoreItem(string $walletId, string $transactionId, int $version): array
{
    return syncItem('transaction', 'restore', $transactionId, ['deleted_at' => null, 'version' => $version], $walletId);
}

function editItem(string $walletId, string $transactionId, int $version, int $amount): array
{
    return syncItem('transaction', 'upsert', $transactionId, transactionBody(['amount_minor' => $amount], $version), $walletId);
}

test('a per-record restore at version n answers n + 1, and a restore of a live transaction answers it unchanged', function () {
    [$credential, $walletId, $transactionId] = deletedTransaction();

    $restored = transactionCall('PATCH', $credential, $walletId, $transactionId, ['deleted_at' => null, 'version' => 2])->assertOk();
    $again = transactionCall('PATCH', $credential, $walletId, $transactionId, ['deleted_at' => null, 'version' => 3])->assertOk();

    expect($restored->json('data.version'))->toBe(3)->and($restored->json('data.deleted_at'))->toBeNull()
        ->and($again->json('data'))->toBe($restored->json('data'));
});

test('a batch of restore (n) then upsert (n + 1) applies both: version n + 2 with the new amount', function () {
    [$credential, $walletId, $transactionId] = deletedTransaction();

    $response = postBatch($credential, [restoreItem($walletId, $transactionId, 2), editItem($walletId, $transactionId, 3, 7700)])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.0.outcome'))->toBe('applied')
        ->and($response->json('data.0.record.version'))->toBe(3)
        ->and($response->json('data.1.outcome'))->toBe('applied')
        ->and($response->json('data.1.record.version'))->toBe(4)
        ->and($response->json('data.1.record.amount_minor'))->toBe(7700)
        ->and($response->json('data.1.record.deleted_at'))->toBeNull();
});

test('a stale restore conflicts and so does the upsert, the stored transaction unchanged', function () {
    [$credential, $walletId, $transactionId] = deletedTransaction();

    $response = postBatch($credential, [restoreItem($walletId, $transactionId, 1), editItem($walletId, $transactionId, 2, 7700)])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.0.outcome'))->toBe('conflict')
        ->and($response->json('data.0.problem.code'))->toBe('VERSION_CONFLICT')
        ->and($response->json('data.1.outcome'))->toBe('conflict')
        ->and($response->json('data.1.problem.code'))->toBe('VERSION_CONFLICT')
        ->and($response->json('data.1.problem.current.deleted_at'))->not->toBeNull();

    $stored = DB::table('transactions')->where('id', $transactionId)->first();
    expect($stored->deleted_at)->not->toBeNull()->and((int) $stored->version)->toBe(2)->and((int) $stored->amount_minor)->toBe(2500);
});

test('a restore refused for permission refuses the upsert and changes nothing', function () {
    [$credential, $walletId, $transactionId] = deletedTransaction('viewer');

    $response = postBatch($credential, [restoreItem($walletId, $transactionId, 2), editItem($walletId, $transactionId, 3, 7700)])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.0.outcome'))->toBe('rejected')
        ->and($response->json('data.1.outcome'))->toBe('rejected');

    $stored = DB::table('transactions')->where('id', $transactionId)->first();
    expect($stored->deleted_at)->not->toBeNull()->and((int) $stored->version)->toBe(2);
});
