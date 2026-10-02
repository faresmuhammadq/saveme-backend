<?php

use App\Modules\Sync\Domain\Cursor\FeedKind;
use App\Modules\Sync\Domain\Cursor\SyncCursor;
use App\Modules\Sync\Domain\Ports\CursorSigner;
use Illuminate\Support\Facades\DB;

/*
 * GET /sync/user/changes (spec 013, US3) against docs/openapi/paths/sync.yaml.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    ownedCategory($this->user->id, ['name' => 'Tea']);
});

test('200: first page, later page, empty page and a clamped limit', function () {
    $first = readFeed($this->credential, 'user', null, 2)->assertValidRequest()->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue()
        ->and($first->json('meta.next_cursor'))->toBe($first->json('meta.sync_cursor'))
        ->and($first->json('meta.limit'))->toBe(2);

    $later = readFeed($this->credential, 'user', $first->json('meta.sync_cursor'))->assertValidResponse(200);
    expect($later->json('data'))->not->toBe([]);

    $empty = readFeed($this->credential, 'user', $later->json('meta.sync_cursor'))->assertValidResponse(200);
    expect($empty->json('data'))->toBe([])
        ->and($empty->json('meta.sync_cursor'))->toBeString()
        ->and($empty->json('meta.next_cursor'))->toBeNull();

    expect(readFeed($this->credential, 'user', null, 5000)->assertValidResponse(200)->json('meta.limit'))->toBe(200);
});

test('400 INVALID_CURSOR for garbage, a wallet cursor, another account\'s cursor and one ahead of the counter', function () {
    withoutSpectatorRequestValidation();
    $other = person('grace@example.test');
    $otherCredential = signInAs('grace@example.test')->json('data.credential');
    $theirs = readFeed($otherCredential, 'user')->json('meta.sync_cursor');
    $walletCursor = readFeed($this->credential, $this->wallet->id)->json('meta.sync_cursor');

    foreach (['garbage', $walletCursor, $theirs] as $cursor) {
        readFeed($this->credential, 'user', $cursor)->assertValidResponse(400)->assertJsonPath('code', 'INVALID_CURSOR');
    }

    $signer = app(CursorSigner::class);
    $ahead = (new SyncCursor(FeedKind::User, $this->user->id, $this->user->id, 9999))->encode($signer);
    readFeed($this->credential, 'user', $ahead)->assertValidResponse(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('400 for filters, sorts and a bad limit; an unknown parameter is ignored', function () {
    withoutSpectatorRequestValidation();

    walletCall('GET', $this->credential, '/sync/user/changes?filter[type]=profile')->assertValidResponse(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, '/sync/user/changes?sort=change_seq')->assertValidResponse(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, '/sync/user/changes?limit=0')->assertValidResponse(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    walletCall('GET', $this->credential, '/sync/user/changes?since=12')->assertOk();
});

test('401 without a credential', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/sync/user/changes')->assertValidResponse(401);
});

test('406 when Accept excludes JSON', function () {
    $this->withHeaders([...asCredential($this->credential), 'Accept' => 'text/html'])->get('/api/v1/sync/user/changes')->assertStatus(406)->assertJsonPath('code', 'NOT_ACCEPTABLE');
});

test('410 SYNC_CURSOR_EXPIRED when the cursor is behind the purge horizon', function () {
    $cursor = readFeed($this->credential, 'user')->json('meta.sync_cursor');
    $stale = DB::table('users')->where('id', $this->user->id)->value('change_seq');
    expect($stale)->toBeGreaterThan(0);
    DB::table('users')->where('id', $this->user->id)->update(['purged_change_seq' => $stale + 1, 'change_seq' => $stale + 1]);

    readFeed($this->credential, 'user', $cursor)->assertValidResponse(410)->assertJsonPath('code', 'SYNC_CURSOR_EXPIRED');
    readFeed($this->credential, 'user')->assertValidResponse(200);
});

test('426 below the client version floor', function () {
    config(['api.minimum_client_version.android' => '9.0.0']);

    $this->withHeaders(asCredential($this->credential))->getJson('/api/v1/sync/user/changes')->assertStatus(426)->assertJsonPath('code', 'CLIENT_VERSION_UNSUPPORTED');
});
