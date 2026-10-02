<?php

namespace App\Modules\Wallets\Domain\Transactions;

use App\Modules\Shared\Domain\Money\Money;
use InvalidArgumentException;

/**
 * One leg of a transaction: a signed amount against exactly one of a wallet
 * or a category (ADR-0012 rule 3).
 */
final readonly class PostingLine
{
    public function __construct(
        public ?string $walletId,
        public ?string $categoryId,
        public Money $money,
    ) {
        if (($walletId === null) === ($categoryId === null)) {
            throw new InvalidArgumentException('A posting references exactly one of a wallet or a category.');
        }

        if ($money->isZero()) {
            throw new InvalidArgumentException('A posting is never zero.');
        }
    }

    public static function forWallet(string $walletId, Money $money): self
    {
        return new self($walletId, null, $money);
    }

    public static function forCategory(string $categoryId, Money $money): self
    {
        return new self(null, $categoryId, $money);
    }
}
