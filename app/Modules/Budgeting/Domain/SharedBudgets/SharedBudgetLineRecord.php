<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets;

use DateTimeImmutable;

/**
 * One category on a Shared Budget's list. `name`, `icon` and `color` are the
 * display fields of a person's category, so every member can render it; all
 * null for a platform category, which every client already holds.
 */
final readonly class SharedBudgetLineRecord
{
    public function __construct(
        public string $id,
        public string $sharedBudgetId,
        public string $categoryId,
        public ?int $limitMinor,
        public ?string $name,
        public ?string $icon,
        public ?string $color,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
        /** @var list<array{applies_from: string, limit_minor: ?int}> from a Budget Period start on */
        public array $limitHistory = [],
    ) {}

    public function isTombstoned(): bool
    {
        return $this->deletedAt !== null;
    }
}
