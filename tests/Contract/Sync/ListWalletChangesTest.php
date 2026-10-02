<?php

use App\Modules\Sync\Domain\Cursor\FeedKind;
use App\Modules\Sync\Domain\Cursor\SyncCursor;
use App\Modules\Sync\Domain\Ports\CursorSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * GET /sync/wallets/{id}/changes (spec 013, US1) against docs/openapi/paths/sync.yaml.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    recordTransaction($this->user->id, $this->wallet->id);
});

test('200: first page, later page and empty page, sync_cursor never null, no postings', function () {
    $first = readFeed($this->credential, $this->wallet->id, null, 2)->assertValidRequest()->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue()->and($first->getContent())->not->toContain('posting');

    $later = readFeed($this->credential, $this->wallet->id, $first->json('meta.sync_cursor'))->assertValidResponse(200);
    $empty = readFeed($this->credential, $this->wallet->id, $later->json('meta.sync_cursor'))->assertValidResponse(200);

    expect($empty->json('data'))->toBe([])->and($empty->json('meta.sync_cursor'))->toBeString();
});

test('400 INVALID_CURSOR for garbage, a user cursor, another wallet\'s cursor and one ahead of the counter', function () {
    withoutSpectatorRequestValidation();
    $otherWallet = ownedWallet($this->user->id);
    $userCursor = readFeed($this->credential, 'user')->json('meta.sync_cursor');
    $otherCursor = readFeed($this->credential, $otherWallet->id)->json('meta.sync_cursor');
    $ahead = (new SyncCursor(FeedKind::Wallet, $this->wallet->id, $this->user->id, 9999))->encode(app(CursorSigner::class));

    foreach (['garbage', $userCursor, $otherCursor, $ahead] as $cursor) {
        readFeed($this->credential, $this->wallet->id, $cursor)->assertValidResponse(400)->assertJsonPath('code', 'INVALID_CURSOR');
    }
});

test('400 for filters and sorts; an unknown parameter is ignored', function () {
    withoutSpectatorRequestValidation();
    $path = '/sync/wallets/'.$this->wallet->id.'/changes';

    walletCall('GET', $this->credential, $path.'?filter[type]=transaction')->assertValidResponse(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?sort=-change_seq')->assertValidResponse(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?since=1')->assertOk();
});

test('401, 404 for a non-member, an unknown wallet and a purged wallet, 422 for a malformed id', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/sync/wallets/'.$this->wallet->id.'/changes')->assertValidResponse(401);

    person('grace@example.test');
    $stranger = signInAs('grace@example.test')->json('data.credential');
    readFeed($stranger, $this->wallet->id)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    readFeed($this->credential, (string) Str::uuid7())->assertValidResponse(404);

    walletCall('GET', $this->credential, '/sync/wallets/not-an-id/changes')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');

    DB::table('postings')->delete();
    DB::table('transactions')->delete();
    DB::table('category_references')->delete();
    DB::table('wallet_members')->delete();
    DB::table('wallets')->delete();
    readFeed($this->credential, $this->wallet->id)->assertValidResponse(404);
});

test('406 when Accept excludes JSON and 426 below the client floor', function () {
    $this->withHeaders([...asCredential($this->credential), 'Accept' => 'text/html'])->get('/api/v1/sync/wallets/'.$this->wallet->id.'/changes')->assertStatus(406);

    config(['api.minimum_client_version.android' => '9.0.0']);
    $this->withHeaders(asCredential($this->credential))->getJson('/api/v1/sync/wallets/'.$this->wallet->id.'/changes')->assertStatus(426);
});

test('410 SYNC_CURSOR_EXPIRED when the cursor is behind the wallet\'s purge horizon', function () {
    $cursor = readFeed($this->credential, $this->wallet->id)->json('meta.sync_cursor');
    $counter = (int) DB::table('wallets')->where('id', $this->wallet->id)->value('change_seq');
    DB::table('wallets')->where('id', $this->wallet->id)->update(['purged_change_seq' => $counter + 1, 'change_seq' => $counter + 1]);

    readFeed($this->credential, $this->wallet->id, $cursor)->assertValidResponse(410)->assertJsonPath('code', 'SYNC_CURSOR_EXPIRED');
    readFeed($this->credential, $this->wallet->id)->assertValidResponse(200);
});
