<?php

/*
 * `meta.sync_position` on both change feeds (spec 017, US1) against
 * docs/openapi/paths/sync.yaml (ChangeFeedMeta).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    recordTransaction($this->user->id, $this->wallet->id);
    ownedCategory($this->user->id);
});

test('the wallet feed sends sync_position: the last row\'s position, repeated by an empty page', function () {
    $page = readFeed($this->credential, $this->wallet->id)->assertValidRequest()->assertValidResponse(200);
    $rows = $page->json('data');
    $last = end($rows);

    expect($page->json('meta.sync_position'))->toBeInt()->toBe($last['record']['change_seq']);

    $empty = readFeed($this->credential, $this->wallet->id, $page->json('meta.sync_cursor'))->assertValidResponse(200);

    expect($empty->json('data'))->toBe([])->and($empty->json('meta.sync_position'))->toBe($page->json('meta.sync_position'));
});

test('the user feed sends sync_position and an intermediate page stops at its last row', function () {
    $first = readFeed($this->credential, 'user', null, 1)->assertValidRequest()->assertValidResponse(200);
    $full = readFeed($this->credential, 'user', null, 200)->assertValidResponse(200);

    expect($first->json('meta.has_more'))->toBeTrue()
        ->and($first->json('meta.sync_position'))->toBeInt()->toBeLessThan($full->json('meta.sync_position'));

    $empty = readFeed($this->credential, 'user', $full->json('meta.sync_cursor'))->assertValidResponse(200);

    expect($empty->json('meta.sync_position'))->toBe($full->json('meta.sync_position'));
});
