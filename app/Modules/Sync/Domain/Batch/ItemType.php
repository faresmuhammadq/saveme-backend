<?php

namespace App\Modules\Sync\Domain\Batch;

/**
 * The record types a batch can write, and the operations each allows: every
 * whole-record write that exists today (spec 013, FR-017, spec 014 FR-023:
 * a rule may be upserted and deleted, an occurrence only upserted, since a
 * client never creates or deletes one; spec 015: a budget upserted and deleted, user-scoped; ADR-0027: a shared budget upserted and deleted). Commands (archive, transfer ownership) and partial edits are not batch operations.
 */
enum ItemType: string
{
    case Wallet = 'wallet';
    case Category = 'category';
    case Transaction = 'transaction';
    case Profile = 'profile';
    case RecurringRule = 'recurring_rule';
    case Occurrence = 'occurrence';
    case Budget = 'budget';
    case SharedBudget = 'shared_budget';

    /**
     * @return list<ItemOperation>
     */
    public function allowedOperations(): array
    {
        return match ($this) {
            self::Wallet, self::Category, self::RecurringRule, self::Budget, self::SharedBudget => [ItemOperation::Upsert, ItemOperation::Delete],
            self::Transaction => [ItemOperation::Upsert, ItemOperation::Delete, ItemOperation::Restore],
            self::Profile, self::Occurrence => [ItemOperation::Upsert],
        };
    }

    public function allows(ItemOperation $operation): bool
    {
        return in_array($operation, $this->allowedOperations(), true);
    }

    /**
     * Whether the item names the wallet of its path (a transaction, a
     * recurring rule and an occurrence do).
     */
    public function needsWalletId(): bool
    {
        return in_array($this, [self::Transaction, self::RecurringRule, self::Occurrence], true);
    }
}
