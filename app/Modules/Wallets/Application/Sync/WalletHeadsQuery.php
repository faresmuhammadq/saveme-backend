<?php

namespace App\Modules\Wallets\Application\Sync;

use App\Modules\Wallets\Domain\Ports\SyncFeedReader;

/**
 * The wallet scope's side of GET /sync/wallets/heads (spec 017, R-4): one
 * entry per live wallet the reader actively belongs to. The membership is
 * the whole authorization; a wallet the reader cannot read is absent, never
 * refused (P-08 rule 3).
 */
final class WalletHeadsQuery
{
    public function __construct(private readonly SyncFeedReader $feed) {}

    /**
     * Ascending by wallet id after $afterWalletId, at most $limit rows.
     *
     * @return list<WalletHead>
     */
    public function page(string $readerId, ?string $afterWalletId, int $limit): array
    {
        return array_map(
            fn (array $row): WalletHead => new WalletHead($row['wallet_id'], $row['change_seq'], $row['purged_change_seq']),
            $this->feed->headsFor($readerId, $afterWalletId, $limit),
        );
    }
}
