<?php

use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * POST/GET /exports and GET /exports/{id} (spec 022), validated against
 * docs/openapi/paths/exports.yaml for every documented status.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    [$this->user, $this->credential] = walletCaller();
});

test('requestExport: 202 for an account export and for a wallet export', function () {
    exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])
        ->assertValidRequest()->assertValidResponse(202);
    DataExport::query()->update(['status' => 'failed', 'failure_code' => 'generation_failed']);

    $wallet = ownedWallet($this->user->id);

    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet', 'wallet_id' => $wallet->id])
        ->assertValidRequest()->assertValidResponse(202)->assertJsonPath('data.wallet_id', $wallet->id);
});

test('requestExport: the shared refusals 401, 406, 415, 426 and 400', function () {
    assertSharedRefusals('POST', '/exports', ['scope' => 'account'], $this->credential);
});

test('requestExport: 400 for a client without its headers', function () {
    $this->withHeaders(['Authorization' => asCredential($this->credential)['Authorization'], 'Accept' => 'application/json'])
        ->postJson('/api/v1/exports', ['scope' => 'account'])
        ->assertValidResponse(400);
});

test('requestExport: 404 for a wallet that is not the caller\'s', function () {
    $other = person('other@example.test');
    $wallet = ownedWallet($other->id);

    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet', 'wallet_id' => $wallet->id])
        ->assertValidResponse(404);
});

test('requestExport: 409 with the active export as current, which validates as an Export', function () {
    $first = exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertValidResponse(202);

    exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'EXPORT_IN_PROGRESS')
        ->assertJsonPath('current.id', $first->json('data.id'))
        ->assertJsonStructure(['current' => ['id', 'scope', 'wallet_id', 'status', 'failure_code', 'requested_at', 'completed_at', 'expires_at', 'size_bytes', 'download_url', 'download_url_expires_at']]);
});

test('requestExport: 422 at /scope and /wallet_id', function () {
    exportCall('POST', $this->credential, '/exports', ['scope' => 'everything'])
        ->assertValidResponse(422)->assertJsonPath('errors.0.pointer', '/scope');

    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet'])
        ->assertValidResponse(422)->assertJsonPath('errors.0.pointer', '/wallet_id');

    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet', 'wallet_id' => 'nope'])
        ->assertValidResponse(422)->assertJsonPath('errors.0.code', 'INVALID_ID');

    exportCall('POST', $this->credential, '/exports', [])
        ->assertValidResponse(422)->assertJsonPath('errors.0.pointer', '/scope');
});

test('requestExport: 429 once five accepted requests are spent', function () {
    foreach (range(1, 5) as $attempt) {
        exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertValidResponse(202);
        DataExport::query()->update(['status' => 'failed', 'failure_code' => 'generation_failed']);
    }

    $limited = exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertValidResponse(429);

    expect($limited->headers->get('Retry-After'))->not->toBeNull();
});

test('listExports: 200 newest first with a cursor, 400 for a bad cursor, 401 without a credential', function () {
    foreach (range(1, 3) as $n) {
        DataExport::factory()->failed()->create(['user_id' => $this->user->id, 'requested_at' => now()->subMinutes(10 - $n)]);
    }

    $page = exportCall('GET', $this->credential, '/exports?limit=2')->assertValidRequest()->assertValidResponse(200);
    expect($page->json('data'))->toHaveCount(2)->and($page->json('meta.has_more'))->toBeTrue();

    $next = exportCall('GET', $this->credential, '/exports?limit=2&cursor='.$page->json('meta.next_cursor'))->assertValidResponse(200);
    expect($next->json('data'))->toHaveCount(1)
        ->and($next->json('meta.has_more'))->toBeFalse()
        ->and(array_column([...$page->json('data'), ...$next->json('data')], 'requested_at'))->toBe(collect([...$page->json('data'), ...$next->json('data')])->sortByDesc('requested_at')->pluck('requested_at')->values()->all());

    exportCall('GET', $this->credential, '/exports?cursor=garbage')->assertValidResponse(400)->assertJsonPath('code', 'INVALID_CURSOR');

    $this->withHeaders(jsonHeaders())->getJson('/api/v1/exports')->assertValidResponse(401);
});

test('listExports: an empty list is 200 with an empty data array', function () {
    exportCall('GET', $this->credential, '/exports')->assertValidResponse(200)->assertJsonPath('data', []);
});

test('getExport: 200 for queued, ready and failed exports', function () {
    $queued = DataExport::factory()->create(['user_id' => $this->user->id]);
    exportCall('GET', $this->credential, '/exports/'.$queued->id)->assertValidResponse(200)->assertJsonPath('data.status', 'queued');
    DataExport::query()->whereKey($queued->id)->update(['status' => 'running']);
    exportCall('GET', $this->credential, '/exports/'.$queued->id)->assertValidResponse(200)->assertJsonPath('data.status', 'running');

    app(GenerateExport::class)->run($queued->id);
    $ready = exportCall('GET', $this->credential, '/exports/'.$queued->id)->assertValidResponse(200);
    expect($ready->json('data.status'))->toBe('ready')->and($ready->json('data.download_url'))->toContain('/exports/'.$queued->id.'/file');

    $failed = DataExport::factory()->failed('timed_out')->create(['user_id' => $this->user->id]);
    exportCall('GET', $this->credential, '/exports/'.$failed->id)->assertValidResponse(200)->assertJsonPath('data.failure_code', 'timed_out');
});

test('getExport: 401, 404 for another person\'s, 422 for a malformed id', function () {
    $other = DataExport::factory()->create(['user_id' => person('other@example.test')->id]);

    $this->withHeaders(jsonHeaders())->getJson('/api/v1/exports/'.$other->id)->assertValidResponse(401);
    exportCall('GET', $this->credential, '/exports/'.$other->id)->assertValidResponse(404);
    exportCall('GET', $this->credential, '/exports/not-a-uuid')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    exportCall('GET', $this->credential, '/exports/'.(string) Str::uuid7())->assertValidResponse(404);
});
