<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Wallets\Application\Transactions\Dto\SpendingTotal;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;

/**
 * What a person spent, for Budgeting (spec 015, FR-010, research R-2): the
 * sum of category-leg postings of the person's own live expenses in the
 * span, in wallets they currently belong to, grouped by category and
 * currency. One query however many budgets ask. Read from postings, never
 * from the wallet's cached balance (P-05).
 */
final class SpendingQueryService
{
    public function __construct(private readonly TransactionRepository $transactions) {}

    /**
     * @param  string  $from  YYYY-MM-DD, inclusive
     * @param  string  $to  YYYY-MM-DD, inclusive
     * @return list<SpendingTotal>
     */
    public function expensesRecordedBy(string $userId, string $from, string $to): array
    {
        return array_map(
            fn (array $row): SpendingTotal => new SpendingTotal($row['category_id'], $row['currency'], $row['amount_minor']),
            $this->transactions->expenseTotalsRecordedBy($userId, $from, $to),
        );
    }
}
