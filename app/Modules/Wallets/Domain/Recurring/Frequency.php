<?php

namespace App\Modules\Wallets\Domain\Recurring;

enum Frequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Whether the frequency counts from an anchor day of the month.
     */
    public function usesAnchorDay(): bool
    {
        return $this === self::Monthly || $this === self::Yearly;
    }
}
