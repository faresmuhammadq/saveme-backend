<?php

namespace App\Modules\Wallets\Domain\Categories;

use DateTimeImmutable;

/**
 * An entry of the platform-owned default set (ADR-0014): reference data with
 * a stable key and a name in each language.
 */
final readonly class PlatformCategoryRecord
{
    /**
     * @param  array{ar: string, en: string}  $names
     */
    public function __construct(
        public string $id,
        public string $systemKey,
        public CategoryKind $kind,
        public array $names,
        public string $icon,
        public string $color,
        public int $position,
        public ?DateTimeImmutable $archivedAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
