<?php

namespace App\Modules\Wallets\Application\Deletion;

use Closure;
use DateTimeImmutable;

/**
 * A module that keeps records inside a wallet registers one of these (tagged
 * with this interface's name) so the records are tombstoned in the same unit
 * of work as the wallet (spec 010, R-9, FR-010): B6 transactions, B7 rules,
 * B9 invitations. It runs inside the deletion's transaction with the wallet
 * row locked; an exception rolls the whole deletion back.
 */
interface WalletDeletionParticipant
{
    /**
     * Tombstone the wallet's records: `deleted_at` = $at, `version` + 1, and
     * each record's own change_seq from $nextChangeSeq.
     *
     * @param  Closure(): int  $nextChangeSeq
     */
    public function tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void;
}
