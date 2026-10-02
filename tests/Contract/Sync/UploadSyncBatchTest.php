<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * POST /sync/batch (spec 013, US2) against docs/openapi/paths/sync.yaml.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->existing = recordTransaction($this->user->id, $this->wallet->id)->transaction;
});

function rawBatch(string $credential, string $content, array $headers = []): TestResponse
{
    $response = test()->call('POST', '/api/v1/sync/batch', [], [], [], test()->transformHeadersToServerVars([...asCredential($credential), 'Content-Type' => 'application/json', ...$headers]), $content);
    test()->flushHeaders();

    return $response;
}

test('200 mixed results: applied, conflict (the record was deleted elsewhere), rejected', function () {
    $categoryId = (string) Str::uuid7();
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/transactions/'.$this->existing->id, ['version' => 1])->assertOk();

    $response = postBatch($this->credential, [
        syncItem('category', 'upsert', $categoryId, categoryBody()),
        syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(['category_id' => $categoryId]), $this->wallet->id),
        syncItem('transaction', 'upsert', $this->existing->id, transactionBody(['description' => 'Late'], 1), $this->wallet->id),
        syncItem('transaction', 'delete', (string) Str::uuid7(), ['version' => 1], (string) Str::uuid7()),
    ])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.*.outcome'))->toBe(['applied', 'applied', 'conflict', 'rejected'])
        ->and($response->json('data.*.status'))->toBe([201, 201, 409, 404])
        ->and($response->json('meta'))->toBe([])
        ->and($response->json('data.2.problem.current.deleted_at'))->not->toBeNull();
});

test('200 with every item rejected', function () {
    $response = postBatch($this->credential, [
        syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(), (string) Str::uuid7()),
        syncItem('wallet', 'delete', (string) Str::uuid7(), ['version' => 1]),
    ])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.*.outcome'))->toBe(['rejected', 'rejected'])->and($response->json('data.*.record'))->toBe([null, null]);
});

test('401 without a credential, 406 for Accept, 415 for a non-JSON body, 400 for malformed JSON, 426 below the floor', function () {
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/sync/batch', ['items' => []])->assertValidResponse(401);

    rawBatch($this->credential, '{"items": []}', ['Accept' => 'text/html'])->assertStatus(406);
    rawBatch($this->credential, 'items=1', ['Content-Type' => 'text/plain'])->assertStatus(415)->assertJsonPath('code', 'UNSUPPORTED_MEDIA_TYPE');
    rawBatch($this->credential, '{bad')->assertStatus(400)->assertJsonPath('code', 'MALFORMED_JSON');

    config(['api.minimum_client_version.android' => '9.0.0']);
    rawBatch($this->credential, '{"items": []}')->assertStatus(426);
});

test('422 VALIDATION_FAILED for each envelope refusal, and nothing is written', function (array $body, string $pointer, string $code) {
    withoutSpectatorRequestValidation();
    $wallets = Wallet::query()->count();
    $categories = Category::query()->count();

    $response = walletCall('POST', $this->credential, '/sync/batch', $body)->assertValidResponse(422);

    expect($response->json('code'))->toBe('VALIDATION_FAILED')
        ->and($response->json('errors.0.pointer'))->toBe($pointer)
        ->and($response->json('errors.0.code'))->toBe($code)
        ->and(Wallet::query()->count())->toBe($wallets)
        ->and(Category::query()->count())->toBe($categories);
})->with([
    'no items' => [[], '/items', 'REQUIRED'],
    'items not an array' => [['items' => 'x'], '/items', 'INVALID_TYPE'],
    'empty items' => [['items' => []], '/items', 'OUT_OF_RANGE'],
    'unknown root member' => [['items' => [], 'x' => 1], '/x', 'UNKNOWN_FIELD'],
]);

test('422 for 51 items and for a bad item, with the valid ones before it not applied', function () {
    withoutSpectatorRequestValidation();
    $good = syncItem('wallet', 'upsert', $walletId = (string) Str::uuid7(), walletBody());

    walletCall('POST', $this->credential, '/sync/batch', ['items' => array_fill(0, 51, $good)])->assertValidResponse(422)->assertJsonPath('errors.0.code', 'OUT_OF_RANGE');
    walletCall('POST', $this->credential, '/sync/batch', ['items' => [$good, ['type' => 'wallet', 'op' => 'restore', 'id' => 'x', 'data' => []]]])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/items/1/op')
        ->assertJsonPath('errors.1.pointer', '/items/1/id')
        ->assertJsonPath('errors.1.code', 'INVALID_ID');

    expect(Wallet::query()->whereKey($walletId)->exists())->toBeFalse();
});

test('an Idempotency-Key header is ignored and nothing is replayed', function () {
    $item = syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody());
    $headers = ['Idempotency-Key' => 'a-key-of-sixteen-chars'];

    $first = rawBatch($this->credential, json_encode(['items' => [$item]]), $headers)->assertOk();
    $second = rawBatch($this->credential, json_encode(['items' => [$item]]), $headers)->assertOk();

    expect($first->json('data.0.status'))->toBe(201)
        ->and($second->json('data.0.status'))->toBe(200)
        ->and($second->headers->has('Idempotency-Replayed'))->toBeFalse();
});
