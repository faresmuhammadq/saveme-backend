<?php

use App\Modules\Sync\Application\ChangeFeedService;
use App\Modules\Sync\Application\FeedResult;
use App\Modules\Sync\Domain\Cursor\FeedKind;
use App\Modules\Sync\Domain\Cursor\SyncCursor;
use App\Modules\Sync\Domain\Ports\CursorSigner;
use Illuminate\Support\Facades\DB;

/*
 * `sync_position` on every feed page (spec 017, US1; ADR-0021): the integer
 * the page's sync_cursor signs.
 */

function signedPosition(FeedResult $result, FeedKind $feed, string $scopeId, string $readerId): int
{
    return SyncCursor::decode($result->syncCursor, app(CursorSigner::class), $feed, $scopeId, $readerId)->position;
}

beforeEach(function () {
    $this->ada = person('ada@example.test');
    $this->grace = person('grace@example.test');
    $this->wallet = ownedWallet($this->ada->id);
    $this->feeds = app(ChangeFeedService::class);
});

test('it equals the position the cursor signs on a first, intermediate and last page, and on an empty page', function () {
    recordTransaction($this->ada->id, $this->wallet->id);
    recordTransaction($this->ada->id, $this->wallet->id);

    $first = $this->feeds->walletPage($this->ada->id, $this->wallet->id, null, 1);
    $second = $this->feeds->walletPage($this->ada->id, $this->wallet->id, $first->syncCursor, 1);
    $last = $this->feeds->walletPage($this->ada->id, $this->wallet->id, $second->syncCursor, 200);
    $empty = $this->feeds->walletPage($this->ada->id, $this->wallet->id, $last->syncCursor, 200);

    expect($first->hasMore)->toBeTrue()
        ->and($first->syncPosition)->toBe(signedPosition($first, FeedKind::Wallet, $this->wallet->id, $this->ada->id))
        ->and($second->syncPosition)->toBeGreaterThan($first->syncPosition)
        ->and($second->syncPosition)->toBe(signedPosition($second, FeedKind::Wallet, $this->wallet->id, $this->ada->id))
        ->and($last->hasMore)->toBeFalse()
        ->and($last->syncPosition)->toBe((int) DB::table('wallets')->where('id', $this->wallet->id)->value('change_seq'))
        ->and($empty->rows)->toBe([])
        ->and($empty->syncPosition)->toBe($last->syncPosition);
});

test('an empty user page from a stored cursor repeats the stored position', function () {
    ownedCategory($this->ada->id);

    $read = $this->feeds->userPage($this->ada->id, 'session', null, 200);
    $again = $this->feeds->userPage($this->ada->id, 'session', $read->syncCursor, 200);

    expect($read->syncPosition)->toBeGreaterThan(0)
        ->and($again->rows)->toBe([])
        ->and($again->syncPosition)->toBe($read->syncPosition);
});

test('a transfer into a wallet ends the destination page at the destination stamp, not the record change_seq', function () {
    $destination = ownedWallet($this->grace->id);
    addMember($destination->id, $this->ada->id, 'editor');
    // Push the source counter well above the destination's.
    foreach (range(1, 4) as $unused) {
        recordTransaction($this->ada->id, $this->wallet->id);
    }
    $transfer = recordTransaction($this->ada->id, $this->wallet->id, transferBody($destination->id))->transaction;

    $page = $this->feeds->walletPage($this->grace->id, $destination->id, null, 200);

    expect($transfer->changeSeq)->toBeGreaterThan($page->syncPosition)
        ->and($page->syncPosition)->toBe((int) DB::table('wallets')->where('id', $destination->id)->value('change_seq'));
});
