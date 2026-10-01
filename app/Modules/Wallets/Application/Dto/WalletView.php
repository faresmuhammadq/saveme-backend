<?php

namespace App\Modules\Wallets\Application\Dto;

use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;

/**
 * A wallet as a read presents it: the record, the caller's role, and whether
 * the currency is locked.
 */
final readonly class WalletView
{
    public function __construct(
        public WalletRecord $wallet,
        public Role $myRole,
        public bool $currencyLocked,
    ) {}
}
