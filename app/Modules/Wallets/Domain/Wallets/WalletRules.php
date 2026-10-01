<?php

namespace App\Modules\Wallets\Domain\Wallets;

use App\Modules\Wallets\Domain\Refusals\WalletFieldImmutable;

/**
 * The state rules of a wallet write (spec 010, FR-002, FR-004): the type
 * never changes, and the currency never changes once a posting references
 * the wallet.
 */
final class WalletRules
{
    /**
     * @throws WalletFieldImmutable
     */
    public static function assertImmutablesKept(WalletRecord $current, WalletType $type, string $currency, bool $currencyLocked): void
    {
        $pointers = [];

        if ($type !== $current->type) {
            $pointers[] = '/type';
        }

        if ($currencyLocked && $currency !== $current->currency) {
            $pointers[] = '/currency';
        }

        if ($pointers !== []) {
            throw new WalletFieldImmutable($pointers);
        }
    }
}
