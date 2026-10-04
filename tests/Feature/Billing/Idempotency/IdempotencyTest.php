<?php

use App\Modules\Billing\Domain\Idempotency\CanonicalJson;
use App\Modules\Billing\Infrastructure\Models\IdempotencyRecord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Idempotency-Key (docs/api-conventions.md; spec 020, FR-002, R-2), through a
 * test-only route that uses the `billing.idempotent` middleware.
 */

beforeEach(function () {
    $this->handled = 0;
    $this->status = 200;
    $counter = $this;

    Route::middleware('api')->prefix('api/v1')->group(function () use ($counter): void {
        $handler = function () use ($counter) {
            $counter->handled++;

            return response()->json(['data' => ['n' => $counter->handled], 'meta' => (object) []], $counter->status);
        };

        Route::post('_idem', $handler)->middleware('billing.idempotent:anonymous');
        Route::post('_idem-user', $handler)->middleware(['auth:api', 'billing.idempotent']);
    });
});

function idemHeaders(string $key): array
{
    return jsonHeaders(['Idempotency-Key' => $key]);
}

function idemKey(): string
{
    return 'key-'.Str::random(20);
}

test('a missing key is 400 IDEMPOTENCY_KEY_REQUIRED', function () {
    $this->postJson('/api/v1/_idem', ['a' => 1], jsonHeaders())
        ->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED')->assertJsonMissingPath('errors');

    expect($this->handled)->toBe(0);
});

test('a malformed key is 400 IDEMPOTENCY_KEY_INVALID', function (string $key) {
    $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($key))
        ->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
})->with([
    '15 characters' => str_repeat('a', 15),
    '256 characters' => str_repeat('a', 256),
    'a space' => 'abcdefgh ijklmnop',
    'a slash' => 'abcdefgh/ijklmnop',
]);

test('a successful answer is stored and replayed verbatim without running the handler again', function () {
    $key = idemKey();

    $first = $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($key))->assertOk()->assertHeaderMissing('Idempotency-Replayed');
    $second = $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($key))->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json())->toEqual($first->json())
        ->and($second->getContent())->toMatch('/"meta": ?\{\}/')
        ->and($this->handled)->toBe(1)
        ->and(IdempotencyRecord::query()->sole()->state)->toBe('completed');
});

test('key order and spacing do not matter to the fingerprint', function () {
    $key = idemKey();

    $this->call('POST', '/api/v1/_idem', [], [], [], collect(idemHeaders($key))->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'], '{"a":1,"b":{"x":1,"y":2}}')->assertOk();
    $this->call('POST', '/api/v1/_idem', [], [], [], collect(idemHeaders($key))->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'], ' { "b" : {"y":2,"x":1}, "a":1 } ')
        ->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($this->handled)->toBe(1);
});

test('the same key with a different body is 409 IDEMPOTENCY_KEY_REUSED', function () {
    $key = idemKey();

    $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($key))->assertOk();
    $this->postJson('/api/v1/_idem', ['a' => 2], idemHeaders($key))
        ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    expect($this->handled)->toBe(1);
});

test('a request still in progress is 409 IDEMPOTENCY_REQUEST_IN_PROGRESS with Retry-After', function () {
    $key = idemKey();
    $this->freezeTime();
    $body = ['a' => 1];

    IdempotencyRecord::query()->create([
        'scope' => 'anonymous', 'key' => $key,
        'fingerprint' => CanonicalJson::fingerprint('POST', '/api/v1/_idem', json_encode($body)),
        'state' => 'in_progress', 'created_at' => now()->subSeconds(10), 'expires_at' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/_idem', $body, idemHeaders($key))
        ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS')->assertHeader('Retry-After', '1');

    expect($this->handled)->toBe(0);
});

test('an in-progress record older than 60 seconds is taken over by the same request', function () {
    $key = idemKey();
    $this->freezeTime();
    $body = ['a' => 1];

    IdempotencyRecord::query()->create([
        'scope' => 'anonymous', 'key' => $key,
        'fingerprint' => CanonicalJson::fingerprint('POST', '/api/v1/_idem', json_encode($body)),
        'state' => 'in_progress', 'created_at' => now()->subSeconds(61), 'expires_at' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/_idem', $body, idemHeaders($key))->assertOk();

    expect($this->handled)->toBe(1)->and(IdempotencyRecord::query()->sole()->state)->toBe('completed');
});

test('a stale in-progress record is not taken over by a different body', function () {
    $key = idemKey();
    $this->freezeTime();

    IdempotencyRecord::query()->create([
        'scope' => 'anonymous', 'key' => $key, 'fingerprint' => str_repeat('a', 64),
        'state' => 'in_progress', 'created_at' => now()->subSeconds(120), 'expires_at' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($key))->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
});

test('a refused answer stores nothing and a corrected retry with the same key succeeds', function (int $status) {
    $key = idemKey();
    $this->status = $status;

    $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($key))->assertStatus($status);
    expect(IdempotencyRecord::query()->count())->toBe(0);

    $this->status = 200;
    $this->postJson('/api/v1/_idem', ['a' => 2], idemHeaders($key))->assertOk()->assertHeaderMissing('Idempotency-Replayed');
    expect($this->handled)->toBe(2);
})->with([422, 429]);

test('an exception releases the key', function () {
    $key = idemKey();
    Route::middleware('api')->prefix('api/v1')->post('_idem-boom', fn () => throw new RuntimeException('boom'))->middleware('billing.idempotent:anonymous');

    $this->postJson('/api/v1/_idem-boom', ['a' => 1], idemHeaders($key))->assertStatus(500);

    expect(IdempotencyRecord::query()->count())->toBe(0);
});

test('the same key under two users is two records, and unauthenticated requests share the anonymous scope', function () {
    person('ada@example.test');
    person('bob@example.test');
    $ada = signInAs('ada@example.test')->json('data.credential');
    $bob = signInAs('bob@example.test')->json('data.credential');
    $key = idemKey();

    $this->postJson('/api/v1/_idem-user', ['a' => 1], [...asCredential($ada), 'Idempotency-Key' => $key])->assertOk();
    $this->postJson('/api/v1/_idem-user', ['a' => 1], [...asCredential($bob), 'Idempotency-Key' => $key])->assertOk()->assertHeaderMissing('Idempotency-Replayed');

    expect($this->handled)->toBe(2)
        ->and(IdempotencyRecord::query()->where('key', $key)->pluck('scope')->all())->toHaveCount(2);

    $other = idemKey();
    $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($other))->assertOk();
    $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($other))->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect(IdempotencyRecord::query()->where('key', $other)->sole()->scope)->toBe('anonymous');
});

test('the purge command deletes records past expiry only', function () {
    $this->freezeTime();
    $row = fn (string $key, $expires) => IdempotencyRecord::query()->create([
        'scope' => 'anonymous', 'key' => $key, 'fingerprint' => str_repeat('a', 64),
        'state' => 'in_progress', 'created_at' => now()->subDays(2), 'expires_at' => $expires,
    ]);
    $row('expired-key-0000001', now()->subSecond());
    $row('live-key-000000001', now()->addMinute());

    Artisan::call('billing:purge-idempotency-records');

    expect(IdempotencyRecord::query()->pluck('key')->all())->toBe(['live-key-000000001']);
});

test('a record past its expiry is replaced even before the purge runs', function () {
    $key = idemKey();
    $this->freezeTime();

    IdempotencyRecord::query()->create([
        'scope' => 'anonymous', 'key' => $key, 'fingerprint' => str_repeat('a', 64),
        'state' => 'completed', 'response_status' => 200, 'response_body' => '{"data":{"old":true},"meta":{}}',
        'created_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
    ]);

    $this->postJson('/api/v1/_idem', ['a' => 1], idemHeaders($key))->assertOk()->assertJsonPath('data.n', 1);
});
