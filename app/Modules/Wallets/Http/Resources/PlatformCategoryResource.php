<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Domain\Categories\PlatformCategoryRecord;

/**
 * A platform category on the wire (spec 011, section 5): reference data with
 * both names, never localised by Accept-Language.
 */
final class PlatformCategoryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PlatformCategoryRecord $category): array
    {
        return [
            'id' => $category->id,
            'system_key' => $category->systemKey,
            'kind' => $category->kind->value,
            'names' => ['ar' => $category->names['ar'], 'en' => $category->names['en']],
            'icon' => $category->icon,
            'color' => $category->color,
            'position' => $category->position,
            'archived_at' => Instants::format($category->archivedAt),
            'updated_at' => Instants::format($category->updatedAt),
        ];
    }
}
