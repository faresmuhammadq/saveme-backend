<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\Dto\AccountView;

final class AccountResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(AccountView $account): array
    {
        return [
            'id' => $account->id,
            'email' => $account->email,
            'email_verified' => $account->emailVerified,
            'state' => $account->state,
            'deletion_scheduled_for' => Instants::format($account->deletionScheduledFor),
        ];
    }
}
