<?php

namespace App\Modules\Wallets\Domain\Categories;

use DateTimeImmutable;

/**
 * A person's Category as the application sees it: every column, no
 * framework type.
 */
final readonly class CategoryRecord
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public CategoryKind $kind,
        public string $name,
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
     * @return array{kind: string, name: string, icon: ?string, color: ?string}
     */
    public function writableValues(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
