<?php

namespace App\Modules\Wallets\Domain\Categories;

use DateTimeImmutable;

/**
 * A Category Reference: the display fields of a person's category as
 * published into one wallet.
 */
final readonly class CategoryReferenceRecord
{
    public function __construct(
        public string $id,
        public string $walletId,
        public string $categoryId,
        public string $ownerUserId,
        public CategoryKind $kind,
        public string $name,
        public ?string $icon,
        public ?string $color,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function isLive(): bool
    {
        return $this->deletedAt === null;
    }
}
