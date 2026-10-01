<?php

namespace App\Modules\Wallets\Application\Dto;

/**
 * The outcome of a wallet save: the wallet as a read presents it, and whether
 * this call created it (201) or not (200).
 */
final readonly class SavedWallet
{
    public function __construct(
        public WalletView $view,
        public bool $created,
    ) {}
}
