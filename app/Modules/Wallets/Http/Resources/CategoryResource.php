<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Application\Categories\Dto\CategoryView;

/**
 * A Category on the wire (spec 011, section 5): the syncable envelope, the
 * owner, the writable members, and the derived archive instant and usage.
 */
final class CategoryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CategoryView $view): array
    {
        $category = $view->category;

        return [
            'id' => $category->id,
            'user_id' => $category->ownerId,
            'version' => $category->version,
            'change_seq' => $category->changeSeq,
            'created_at' => Instants::format($category->createdAt),
            'updated_at' => Instants::format($category->updatedAt),
            'deleted_at' => Instants::format($category->deletedAt),
            'kind' => $category->kind->value,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
            'archived_at' => Instants::format($category->archivedAt),
            'in_use' => $view->inUse,
        ];
    }
}
