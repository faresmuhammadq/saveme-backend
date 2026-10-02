<?php

use App\Modules\Sync\Domain\Cursor\CursorRefusal;
use App\Modules\Sync\Domain\Cursor\FeedKind;
use App\Modules\Sync\Domain\Cursor\SyncCursor;
use App\Modules\Sync\Domain\Refusals\CursorInvalid;
use App\Modules\Sync\Infrastructure\HmacCursorSigner;

/*
 * The signed cursor (spec 013, R-9): bound to feed, scope and reader.
 */

function cursorRefusalOf(Closure $decode): ?CursorRefusal
{
    try {
        $decode();
    } catch (CursorInvalid $e) {
        return $e->reason;
    }

    return null;
}

beforeEach(function () {
    $this->signer = new HmacCursorSigner('test-key');
    $this->cursor = (new SyncCursor(FeedKind::Wallet, 'wallet-1', 'reader-1', 42))->encode($this->signer);
});

test('a cursor round-trips for the feed, scope and reader it was issued to', function () {
    $decoded = SyncCursor::decode($this->cursor, $this->signer, FeedKind::Wallet, 'wallet-1', 'reader-1');

    expect($decoded->position)->toBe(42)
        ->and($decoded->scopeId)->toBe('wallet-1')
        ->and($decoded->readerId)->toBe('reader-1')
        ->and($this->cursor)->toMatch('/^[A-Za-z0-9_-]+$/');
});

test('the purge horizon the read began under round-trips, and a cursor without it reads as 0', function () {
    $withHorizon = (new SyncCursor(FeedKind::Wallet, 'wallet-1', 'reader-1', 16, 29))->encode($this->signer);

    expect(SyncCursor::decode($withHorizon, $this->signer, FeedKind::Wallet, 'wallet-1', 'reader-1')->horizon)->toBe(29)
        ->and(SyncCursor::decode($this->cursor, $this->signer, FeedKind::Wallet, 'wallet-1', 'reader-1')->horizon)->toBe(0);
});

test('a malformed horizon is refused as malformed', function (mixed $horizon) {
    $payload = (string) json_encode(['v' => 1, 'f' => 'w', 's' => 'wallet-1', 'r' => 'reader-1', 'p' => 1, 'h' => $horizon]);
    $cursor = rtrim(strtr(base64_encode($payload.substr($this->signer->sign($payload), 0, 16)), '+/', '-_'), '=');

    expect(cursorRefusalOf(fn () => SyncCursor::decode($cursor, $this->signer, FeedKind::Wallet, 'wallet-1', 'reader-1')))->toBe(CursorRefusal::Malformed);
})->with([-1, 'x', 1.5]);

test('each way a cursor can be wrong is its own refusal', function (string $reason, string $case) {
    $signer = $this->signer;
    $cursor = $this->cursor;
    $decode = fn (string $given, ?HmacCursorSigner $with = null, FeedKind $feed = FeedKind::Wallet, string $scope = 'wallet-1', string $reader = 'reader-1'): SyncCursor => SyncCursor::decode($given, $with ?? $signer, $feed, $scope, $reader);

    $versionTwo = function () use ($signer): string {
        $payload = (string) json_encode(['v' => 2, 'f' => 'w', 's' => 'wallet-1', 'r' => 'reader-1', 'p' => 1]);

        return rtrim(strtr(base64_encode($payload.substr($signer->sign($payload), 0, 16)), '+/', '-_'), '=');
    };

    $attempt = match ($case) {
        'tampered' => fn () => $decode(substr($cursor, 0, -2).'AA'),
        'other key' => fn () => $decode($cursor, new HmacCursorSigner('other')),
        'other feed' => fn () => $decode($cursor, feed: FeedKind::User),
        'other scope' => fn () => $decode($cursor, scope: 'wallet-2'),
        'other reader' => fn () => $decode($cursor, reader: 'reader-2'),
        'not base64' => fn () => $decode('not a cursor!'),
        'too short' => fn () => $decode('abc'),
        'unknown version' => fn () => $decode($versionTwo()),
    };

    expect(cursorRefusalOf($attempt))->toBe(CursorRefusal::from($reason));
})->with([
    ['bad_signature', 'tampered'],
    ['bad_signature', 'other key'],
    ['wrong_feed', 'other feed'],
    ['wrong_scope', 'other scope'],
    ['wrong_reader', 'other reader'],
    ['malformed', 'not base64'],
    ['malformed', 'too short'],
    ['unknown_version', 'unknown version'],
]);
