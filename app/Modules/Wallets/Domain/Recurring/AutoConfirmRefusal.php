<?php

namespace App\Modules\Wallets\Domain\Recurring;

/**
 * Why the server could not confirm an occurrence automatically (spec 014,
 * FR-018). It is not tried again until a member's save clears it.
 */
enum AutoConfirmRefusal: string
{
    case NotPermitted = 'not_permitted';
    case CounterWalletUnavailable = 'counter_wallet_unavailable';
    case CurrencyMismatch = 'currency_mismatch';
}
