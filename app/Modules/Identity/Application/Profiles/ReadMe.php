<?php

namespace App\Modules\Identity\Application\Profiles;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Dto\AccountView;
use App\Modules\Identity\Domain\Preferences\ProfileRecord;

/**
 * The signed-in person's account and profile, for GET /me (spec 008, US1).
 */
final class ReadMe
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly Profiles $profiles,
    ) {}

    /**
     * @return array{account: AccountView, profile: ProfileRecord, currencyLocked: bool}
     */
    public function handle(string $userId): array
    {
        return [
            'account' => $this->accounts->view($userId),
            'profile' => $this->profiles->find($userId),
            'currencyLocked' => $this->profiles->isCurrencyLocked($userId),
        ];
    }
}
