<?php

namespace App\Modules\Wallets\Domain\Categories\Ports;

use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;
use Closure;
use DateTimeImmutable;

interface CategoryReferenceRepository
{
    public function liveFor(string $walletId, string $categoryId): ?CategoryReferenceRecord;

    /**
     * The pair's reference, live or tombstoned.
     */
    public function anyFor(string $walletId, string $categoryId): ?CategoryReferenceRecord;

    /**
     * Ids of the wallets that hold a live reference to the category,
     * ascending (the order wallets are locked in).
     *
     * @return list<string>
     */
    public function liveWalletIdsFor(string $categoryId): array;

    /**
     * Inserts version 1 with the category's display fields.
     */
    public function insert(string $walletId, CategoryRecord $category, int $changeSeq): CategoryReferenceRecord;

    /**
     * Writes the category's display fields, clears any tombstone, version + 1.
     */
    public function refresh(string $referenceId, CategoryRecord $category, int $changeSeq): void;

    /**
     * Tombstones the reference, version + 1.
     */
    public function tombstone(string $referenceId, DateTimeImmutable $at, int $changeSeq): void;

    /**
     * Tombstones every live reference in the wallet, each with its own
     * change_seq from $nextChangeSeq.
     *
     * @param  Closure(): int  $nextChangeSeq
     */
    public function tombstoneAllInWallet(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void;

    /**
     * Live references in publication order, one page plus one.
     *
     * @param  array{value: string, id: string}|null  $after
     * @return list<CategoryReferenceRecord>
     */
    public function listLive(string $walletId, ?array $after, int $limit): array;
}
