<?php

namespace App\Modules\Sync\Domain\Batch;

/**
 * The record types a batch can write, and the operations each allows: every
 * whole-record write that exists today (spec 013, FR-017). Commands (archive,
 * transfer ownership) and partial edits are not batch operations.
 */
enum ItemType: string
{
    case Wallet = 'wallet';
    case Category = 'category';
    case Transaction = 'transaction';
    case Profile = 'profile';

    /**
     * @return list<ItemOperation>
     */
    public function allowedOperations(): array
    {
        return match ($this) {
            self::Wallet, self::Category => [ItemOperation::Upsert, ItemOperation::Delete],
            self::Transaction => [ItemOperation::Upsert, ItemOperation::Delete, ItemOperation::Restore],
            self::Profile => [ItemOperation::Upsert],
        };
    }

    public function allows(ItemOperation $operation): bool
    {
        return in_array($operation, $this->allowedOperations(), true);
    }

    /**
     * Whether the item names the wallet of its path (a transaction does).
     */
    public function needsWalletId(): bool
    {
        return $this === self::Transaction;
    }
}
