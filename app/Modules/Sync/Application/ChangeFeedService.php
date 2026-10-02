<?php

namespace App\Modules\Sync\Application;

use App\Modules\Identity\Application\Sync\FeedRecord as IdentityRecord;
use App\Modules\Identity\Application\Sync\UserFeedQuery as IdentityUserFeed;
use App\Modules\Sync\Domain\Cursor\CursorRefusal;
use App\Modules\Sync\Domain\Cursor\FeedKind;
use App\Modules\Sync\Domain\Cursor\SyncCursor;
use App\Modules\Sync\Domain\Feed\FeedMerge;
use App\Modules\Sync\Domain\Feed\FeedRow;
use App\Modules\Sync\Domain\Ports\CursorSigner;
use App\Modules\Sync\Domain\Refusals\CursorExpired;
use App\Modules\Sync\Domain\Refusals\CursorInvalid;
use App\Modules\Wallets\Application\Sync\FeedRecord as WalletsRecord;
use App\Modules\Wallets\Application\Sync\UserFeedQuery as WalletsUserFeed;
use App\Modules\Wallets\Application\Sync\WalletFeedQuery;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use Illuminate\Support\Facades\Log;

/**
 * The two change feeds (spec 013, R-8 to R-10, R-12). A page is the merge by
 * position of the sources the owning modules offer, rendered by those
 * modules as their reads render them. The scope's counter is read before the
 * sources, and nothing above it is served, so a page never skips a record
 * (see FeedMerge). One summary line per read; no ids, bodies or cursors.
 */
final class ChangeFeedService
{
    public function __construct(
        private readonly IdentityUserFeed $identityFeed,
        private readonly WalletsUserFeed $walletsUserFeed,
        private readonly WalletFeedQuery $walletFeed,
        private readonly CursorSigner $signer,
    ) {}

    /**
     * The caller's user-scope feed.
     *
     * @throws CursorInvalid
     * @throws CursorExpired
     */
    public function userPage(string $readerId, string $readerSessionId, ?string $cursor, int $limit): FeedResult
    {
        $scope = $this->identityFeed->scope($readerId);
        $after = $this->positionOf($cursor, FeedKind::User, $readerId, $readerId, $scope);

        $sources = [
            array_map(fn (IdentityRecord $row): FeedRow => new FeedRow($row->type, $row->position, $row->record), $this->identityFeed->page($readerId, $readerSessionId, $after, $limit)),
            array_map(fn (WalletsRecord $row): FeedRow => new FeedRow($row->type, $row->position, $row->record), $this->walletsUserFeed->page($readerId, $after, $limit)),
        ];

        return $this->result(FeedKind::User, $readerId, $readerId, $sources, $after, $limit, $scope['counter'], $cursor === null);
    }

    /**
     * One wallet's feed.
     *
     * @throws CursorInvalid
     * @throws CursorExpired
     * @throws WalletNotFound
     */
    public function walletPage(string $readerId, string $walletId, ?string $cursor, int $limit): FeedResult
    {
        $scope = $this->walletFeed->scope($readerId, $walletId);
        $after = $this->positionOf($cursor, FeedKind::Wallet, $walletId, $readerId, $scope);

        $sources = [
            array_map(fn (WalletsRecord $row): FeedRow => new FeedRow($row->type, $row->position, $row->record), $this->walletFeed->page($readerId, $walletId, $after, $limit)),
        ];

        return $this->result(FeedKind::Wallet, $walletId, $readerId, $sources, $after, $limit, $scope['counter'], $cursor === null);
    }

    /**
     * @param  array{counter: int, purged: int}  $scope
     */
    private function positionOf(?string $cursor, FeedKind $feed, string $scopeId, string $readerId, array $scope): int
    {
        if ($cursor === null) {
            return 0;
        }

        $position = SyncCursor::decode($cursor, $this->signer, $feed, $scopeId, $readerId)->position;

        if ($position > $scope['counter']) {
            throw new CursorInvalid(CursorRefusal::AheadOfScope);
        }

        if ($position < $scope['purged']) {
            throw new CursorExpired;
        }

        return $position;
    }

    /**
     * @param  list<list<FeedRow>>  $sources
     */
    private function result(FeedKind $feed, string $scopeId, string $readerId, array $sources, int $after, int $limit, int $counter, bool $cursorAbsent): FeedResult
    {
        $page = FeedMerge::merge($sources, $after, $limit, $counter);

        Log::info('sync_feed_read', [
            'feed' => $feed === FeedKind::User ? 'user' : 'wallet',
            'rows' => count($page->rows),
            'has_more' => $page->hasMore,
            'cursor_absent' => $cursorAbsent,
        ]);

        return new FeedResult(
            $page->rows,
            $page->hasMore,
            $limit,
            (new SyncCursor($feed, $scopeId, $readerId, $page->nextPosition))->encode($this->signer),
        );
    }
}
