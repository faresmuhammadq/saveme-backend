<?php

namespace App\Modules\Wallets\Application;

/**
 * What other modules may ask Wallets & Ledger about transactions. B2 needs one
 * question; B6 implements it over the ledger and adds the rest.
 */
interface TransactionQueryService
{
    /**
     * Whether the person has recorded at least one live transaction
     * (created_by = them, deleted_at null) in any wallet. Locks their default
     * currency (spec 008, R-3).
     */
    public function hasLiveTransactionsRecordedBy(string $userId): bool;

    /**
     * Who recorded a live transaction and what it is, for Budgeting to
     * evaluate the author's budgets (spec 023, FR-026). Null when the
     * transaction is gone or deleted. Identifiers, a currency code, a calendar
     * date and the kind only: no amount, no text.
     *
     * @return array{author_id: string, currency: string, date: string, category_id: ?string, kind: string}|null
     */
    public function authorAndCurrencyOf(string $transactionId): ?array;
}
