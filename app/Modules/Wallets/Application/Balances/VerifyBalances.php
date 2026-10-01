<?php

namespace App\Modules\Wallets\Application\Balances;

use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The drift check (ADR-0005 rule 6; spec 010, FR-007). For each live wallet,
 * under its row lock, the cache is recomputed from the opening balance and
 * the postings; where it differed, the drift is logged with the wallet id
 * only (never an amount, security baseline E2) and the column is repaired.
 */
final class VerifyBalances
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly PostingTotals $postings,
    ) {}

    /**
     * @return int the number of wallets whose cache had drifted
     */
    public function handle(): int
    {
        $drifted = 0;

        foreach ($this->wallets->liveIds() as $walletId) {
            DB::transaction(function () use ($walletId, &$drifted): void {
                $wallet = $this->wallets->findForUpdate($walletId);

                if ($wallet === null || $wallet->isTombstone()) {
                    return;
                }

                if ($this->wallets->refreshBalance($walletId, $this->postings->sumFor($walletId))) {
                    $drifted++;
                    Log::warning('wallet_balance_drift', ['wallet_id' => $walletId]);
                }
            });
        }

        return $drifted;
    }
}
