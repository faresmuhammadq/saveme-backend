<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use Illuminate\Support\Str;

/*
 * Each batch item answers exactly what its single write answers (spec 013,
 * US2, FR-014; ADR-0016 rule 1). For every type and operation, the write is
 * sent alone for one person and in a batch for another with an identical
 * fixture; status and record or problem must match.
 */

/**
 * Ids, instants and sequence numbers differ between two fixtures and are not the comparison.
 */
function parityShape(mixed $value): string
{
    $json = (string) json_encode($value);
    $json = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'ID', $json);
    $json = (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z/', 'T', $json);

    return (string) preg_replace('/"(change_seq|version)":\d+/', '"$1":0', $json);
}

/**
 * The single request a batch item stands for, written out independently of the dispatcher.
 *
 * @param  array<string, mixed>  $item
 * @return array{string, string, array<string, mixed>}
 */
function singleRequestFor(array $item): array
{
    $id = $item['id'];
    $wallet = $item['wallet_id'] ?? null;

    return match ([$item['type'], $item['op']]) {
        ['wallet', 'upsert'] => ['PUT', "/wallets/{$id}", $item['data']],
        ['wallet', 'delete'] => ['DELETE', "/wallets/{$id}", $item['data']],
        ['category', 'upsert'] => ['PUT', "/categories/{$id}", $item['data']],
        ['category', 'delete'] => ['DELETE', "/categories/{$id}", $item['data']],
        ['transaction', 'upsert'] => ['PUT', "/wallets/{$wallet}/transactions/{$id}", $item['data']],
        ['transaction', 'delete'] => ['DELETE', "/wallets/{$wallet}/transactions/{$id}", $item['data']],
        ['transaction', 'restore'] => ['PATCH', "/wallets/{$wallet}/transactions/{$id}", $item['data']],
        ['profile', 'upsert'] => ['PUT', '/me/profile', $item['data']],
    };
}

/**
 * @return array<string, array{bool, Closure(string): array<string, mixed>}> verified?, fixture builder given the person's id
 */
function parityScenarios(): array
{
    $newWallet = fn (string $userId, array $changes = []) => ownedWallet($userId, ['name' => 'Fixture', ...$changes]);

    return [
        'wallet create' => [true, fn (string $u) => syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody())],
        'wallet update' => [true, function (string $u) use ($newWallet) {
            $w = $newWallet($u);

            return syncItem('wallet', 'upsert', $w->id, walletBody(['name' => 'Renamed', 'opening_balance_minor' => $w->opening_balance_minor, 'type' => $w->type, 'icon' => null, 'color' => null], 1));
        }],
        'wallet replay' => [true, function (string $u) use ($newWallet) {
            $w = $newWallet($u, ['name' => 'Cash', 'opening_balance_minor' => 50000, 'icon' => 'wallet', 'color' => '#2E7D32']);

            return syncItem('wallet', 'upsert', $w->id, walletBody([], 1));
        }],
        'wallet stale' => [true, function (string $u) use ($newWallet) {
            $w = $newWallet($u, ['version' => 3]);

            return syncItem('wallet', 'upsert', $w->id, walletBody(['name' => 'Stale'], 1));
        }],
        'wallet delete' => [true, fn (string $u) => syncItem('wallet', 'delete', $newWallet($u)->id, ['version' => 1])],
        'wallet unknown on update' => [true, fn (string $u) => syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody(['name' => 'Ghost'], 4))],
        'wallet invalid' => [true, fn (string $u) => syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody(['currency' => 'egp']))],
        'wallet unverified' => [false, fn (string $u) => syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody())],
        'category create' => [true, fn (string $u) => syncItem('category', 'upsert', (string) Str::uuid7(), categoryBody())],
        'category update' => [true, fn (string $u) => syncItem('category', 'upsert', ownedCategory($u, ['name' => 'Fixture'])->id, categoryBody(['name' => 'Renamed', 'kind' => 'expense'], 1))],
        'category delete' => [true, fn (string $u) => syncItem('category', 'delete', ownedCategory($u, ['name' => 'Fixture'])->id, ['version' => 1])],
        'category delete in use' => [true, function (string $u) use ($newWallet) {
            $c = ownedCategory($u, ['name' => 'Fixture']);
            $w = $newWallet($u);
            recordTransaction($u, $w->id, transactionBody(['category_id' => $c->id]));

            return syncItem('category', 'delete', $c->id, ['version' => 1]);
        }],
        'transaction create' => [true, fn (string $u) => syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(), $newWallet($u)->id)],
        'transfer create' => [true, fn (string $u) => syncItem('transaction', 'upsert', (string) Str::uuid7(), transferBody($newWallet($u)->id), $newWallet($u)->id)],
        'transaction amount not integer' => [true, fn (string $u) => syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(['amount_minor' => '12.5']), $newWallet($u)->id)],
        'transaction in unknown wallet' => [true, fn (string $u) => syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(), (string) Str::uuid7())],
        'transaction by a viewer' => [true, function (string $u) use ($newWallet) {
            $other = person('owner-'.Str::lower(Str::random(6)).'@example.test');
            $w = $newWallet($other->id);
            addMember($w->id, $u, 'viewer');

            return syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(), $w->id);
        }],
        'transaction delete' => [true, function (string $u) use ($newWallet) {
            $w = $newWallet($u);

            return syncItem('transaction', 'delete', recordTransaction($u, $w->id)->transaction->id, ['version' => 1], $w->id);
        }],
        'transaction restore' => [true, function (string $u) use ($newWallet) {
            $w = $newWallet($u);
            $t = recordTransaction($u, $w->id)->transaction;
            app(TransactionService::class)->delete($u, $w->id, $t->id, 1, presentTransaction());

            return syncItem('transaction', 'restore', $t->id, ['deleted_at' => null, 'version' => 2], $w->id);
        }],
        'transaction stale' => [true, function (string $u) use ($newWallet) {
            $w = $newWallet($u);
            $t = recordTransaction($u, $w->id)->transaction;
            saveTransaction($u, $w->id, $t->id, transactionBody(['description' => 'Edited'], 1));

            return syncItem('transaction', 'upsert', $t->id, transactionBody(['description' => 'Mine'], 1), $w->id);
        }],
        'profile upsert' => [true, fn (string $u) => syncItem('profile', 'upsert', Profile::query()->where('user_id', $u)->firstOrFail()->id, profileBody(['timezone' => 'Asia/Tokyo']))],
        'profile stale' => [true, fn (string $u) => syncItem('profile', 'upsert', Profile::query()->where('user_id', $u)->firstOrFail()->id, profileBody(['timezone' => 'Asia/Tokyo'], 7))],
    ];
}

test('a batch item answers what the same single write answers', function (string $name) {
    [$verified, $fixture] = parityScenarios()[$name];

    $slug = Str::slug($name);
    $single = person("single-{$slug}@example.test", 'correct horse', $verified);
    $batched = person("batch-{$slug}@example.test", 'correct horse', $verified);
    $singleCredential = signInAs("single-{$slug}@example.test")->json('data.credential');
    $batchedCredential = signInAs("batch-{$slug}@example.test")->json('data.credential');

    $singleItem = $fixture($single->id);
    $batchItem = $fixture($batched->id);

    [$method, $uri, $body] = singleRequestFor($singleItem);
    $alone = walletCall($method, $singleCredential, $uri, $body);

    [$batchMethod, $batchUri, $batchBody] = singleRequestFor($batchItem);
    // A scenario that sends an invalid body is not a valid request by design; the response is still validated.
    in_array($name, ['wallet invalid', 'transaction amount not integer'], true) ? withoutSpectatorRequestValidation() : null;
    $response = postBatch($batchedCredential, [$batchItem]);
    in_array($name, ['wallet invalid', 'transaction amount not integer'], true) ? $response->assertValidResponse(200) : $response->assertValidRequest()->assertValidResponse(200);
    $result = $response->json('data.0');

    $answer = $alone->status() >= 400 ? $alone->json() : $alone->json('data');
    $sent = $result['record'] ?? $result['problem'];

    expect($result['status'])->toBe($alone->status())
        ->and($result['id'])->toBe($batchItem['id'])
        ->and($result['type'])->toBe($batchItem['type'])
        ->and(parityShape($sent))->toBe(parityShape($answer))
        ->and($result['outcome'])->toBe(match (true) {
            $alone->status() === 200, $alone->status() === 201 => 'applied',
            $alone->status() === 409 && ($alone->json('code') === 'VERSION_CONFLICT') => 'conflict',
            default => 'rejected',
        });
})->with(array_keys(parityScenarios()));

test('a profile item with an id other than the caller\'s profile is 404 NOT_FOUND', function () {
    [$user, $credential] = walletCaller();

    $result = postBatch($credential, [syncItem('profile', 'upsert', (string) Str::uuid7(), profileBody())])->assertValidResponse(200)->json('data.0');

    expect($result['status'])->toBe(404)->and($result['problem']['code'])->toBe('NOT_FOUND')->and($result['outcome'])->toBe('rejected');
});

test('a profile item from a caller with no profile row is 404 NOT_FOUND, never a 500', function () {
    [$user, $credential] = walletCaller();
    Profile::query()->where('user_id', $user->id)->delete();

    $result = postBatch($credential, [syncItem('profile', 'upsert', (string) Str::uuid7(), profileBody())])->assertValidResponse(200)->json('data.0');

    expect($result['status'])->toBe(404)->and($result['problem']['code'])->toBe('NOT_FOUND')->and($result['outcome'])->toBe('rejected');
});
