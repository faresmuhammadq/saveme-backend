<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Application\Dto\WalletView;

/**
 * A Wallet on the wire (spec 010, section 5): the syncable envelope, the
 * wallet scope key (its own id), the writable members, the cached balance,
 * and the derived currency lock and caller role.
 */
final class WalletResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(WalletView $view): array
    {
        $wallet = $view->wallet;

        return [
            'id' => $wallet->id,
            'wallet_id' => $wallet->id,
            'version' => $wallet->version,
            'change_seq' => $wallet->changeSeq,
            'created_at' => Instants::format($wallet->createdAt),
            'updated_at' => Instants::format($wallet->updatedAt),
            'deleted_at' => Instants::format($wallet->deletedAt),
            'type' => $wallet->type->value,
            'name' => $wallet->name,
            'currency' => $wallet->currency,
            'opening_balance_minor' => $wallet->openingBalanceMinor,
            'balance_minor' => $wallet->balanceMinor,
            'icon' => $wallet->icon,
            'color' => $wallet->color,
            'archived_at' => Instants::format($wallet->archivedAt),
            'currency_locked' => $view->currencyLocked,
            'my_role' => $view->myRole->value,
        ];
    }
}
