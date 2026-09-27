<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Preferences\ProfileRecord;

/**
 * A Profile on the wire: the syncable envelope, the user scope key, the five
 * preferences, and the derived currency lock (spec 008).
 */
final class ProfileResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ProfileRecord $profile, bool $currencyLocked): array
    {
        return [
            'id' => $profile->id,
            'version' => $profile->version,
            'change_seq' => $profile->changeSeq,
            'created_at' => Instants::format($profile->createdAt),
            'updated_at' => Instants::format($profile->updatedAt),
            'deleted_at' => null,
            'user_id' => $profile->userId,
            'display_name' => $profile->displayName,
            'locale' => $profile->locale,
            'timezone' => $profile->timezone,
            'default_currency' => $profile->defaultCurrency,
            'default_currency_locked' => $currencyLocked,
            'budget_period_start_day' => $profile->budgetPeriodStartDay,
            'budget_period_start_day_from' => $profile->budgetPeriodStartDayFrom,
        ];
    }
}
