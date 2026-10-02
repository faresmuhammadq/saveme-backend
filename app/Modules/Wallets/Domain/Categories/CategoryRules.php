<?php

namespace App\Modules\Wallets\Domain\Categories;

use App\Modules\Wallets\Domain\Categories\Refusals\CategoryFieldImmutable;

/**
 * The state rules of a category write (spec 011, FR-002, A-9): the kind never
 * changes, and a name is 1 to 50 characters once trimmed.
 */
final class CategoryRules
{
    public const NAME_MAX = 50;

    /**
     * @throws CategoryFieldImmutable
     */
    public static function assertKindUnchanged(CategoryRecord $current, CategoryKind $kind): void
    {
        if ($kind !== $current->kind) {
            throw new CategoryFieldImmutable;
        }
    }

    public static function normaliseName(string $name): string
    {
        return trim($name);
    }

    public static function isValidName(string $name): bool
    {
        $length = mb_strlen(self::normaliseName($name));

        return $length >= 1 && $length <= self::NAME_MAX;
    }

    /**
     * Whether a write changes nothing: every writable member equals the
     * stored one, the name compared after trimming.
     *
     * @param  array{kind: string, name: string, icon: ?string, color: ?string}  $values
     */
    public static function isIdentical(CategoryRecord $current, array $values): bool
    {
        $values['name'] = self::normaliseName($values['name']);

        return $values === $current->writableValues();
    }
}
