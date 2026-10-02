<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;

/**
 * A Category Reference on the wire (spec 011, section 5): the syncable
 * envelope, the wallet scope key, and the copied display fields.
 */
final class CategoryReferenceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CategoryReferenceRecord $reference): array
    {
        return [
            'id' => $reference->id,
            'wallet_id' => $reference->walletId,
            'version' => $reference->version,
            'change_seq' => $reference->changeSeq,
            'created_at' => Instants::format($reference->createdAt),
            'updated_at' => Instants::format($reference->updatedAt),
            'deleted_at' => Instants::format($reference->deletedAt),
            'category_id' => $reference->categoryId,
            'owner_user_id' => $reference->ownerUserId,
            'kind' => $reference->kind->value,
            'name' => $reference->name,
            'icon' => $reference->icon,
            'color' => $reference->color,
        ];
    }
}
