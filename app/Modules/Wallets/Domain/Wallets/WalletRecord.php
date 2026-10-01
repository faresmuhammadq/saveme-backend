<?php

namespace App\Modules\Wallets\Domain\Wallets;

use DateTimeImmutable;

/**
 * A Wallet as the application sees it: every column, no framework type.
 */
final readonly class WalletRecord
{
    public function __construct(
        public string $id,
        public WalletType $type,
        public string $name,
        public string $currency,
        public int $openingBalanceMinor,
        public int $balanceMinor,
        public ?string $icon,
        public ?string $color,
        public ?DateTimeImmutable $archivedAt,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function isTombstone(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * The members a client writes, in the shape a write is compared against.
     *
     * @return array{type: string, name: string, currency: string, opening_balance_minor: int, icon: ?string, color: ?string}
     */
    public function writableValues(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'currency' => $this->currency,
            'opening_balance_minor' => $this->openingBalanceMinor,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
