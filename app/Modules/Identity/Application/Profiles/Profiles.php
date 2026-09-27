<?php

namespace App\Modules\Identity\Application\Profiles;

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Preferences\ProfileRecord;
use App\Modules\Wallets\Application\TransactionQueryService;

/**
 * Reads a person's profile with the display name opened, and answers whether
 * their default currency is locked (spec 008, FR-010, R-3).
 */
final class Profiles
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly AttributeCipher $cipher,
        private readonly TransactionQueryService $transactions,
    ) {}

    public function find(string $userId): ProfileRecord
    {
        $row = $this->profiles->findSealed($userId);

        return new ProfileRecord(
            id: $row['id'],
            userId: $row['user_id'],
            displayName: $row['display_name_ciphertext'] === null ? null : $this->cipher->open($userId, $row['display_name_ciphertext']),
            locale: $row['locale'],
            timezone: $row['timezone'],
            defaultCurrency: $row['default_currency'],
            budgetPeriodStartDay: $row['budget_period_start_day'],
            budgetPeriodStartDayFrom: $row['budget_period_start_day_from'],
            version: $row['version'],
            changeSeq: $row['change_seq'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    /**
     * Locked once the person has recorded a live transaction in any wallet.
     */
    public function isCurrencyLocked(string $userId): bool
    {
        return $this->transactions->hasLiveTransactionsRecordedBy($userId);
    }
}
