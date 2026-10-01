<?php

namespace App\Modules\Wallets\Domain\Wallets;

/**
 * The fixed set of wallet types (glossary). A type never changes once the
 * wallet exists: changing it would invert the sign convention of its balance.
 */
enum WalletType: string
{
    case Cash = 'cash';
    case Checking = 'checking';
    case Savings = 'savings';
    case CreditCard = 'credit_card';
    case Loan = 'loan';
    case Investment = 'investment';

    /**
     * Liabilities hold money owed, stored as a negative balance.
     */
    public function isLiability(): bool
    {
        return match ($this) {
            self::CreditCard, self::Loan => true,
            default => false,
        };
    }
}
