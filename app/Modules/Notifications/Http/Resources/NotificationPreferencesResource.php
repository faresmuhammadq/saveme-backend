<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Domain\Preferences\Preferences;
use App\Modules\Notifications\Domain\Preferences\QuietHours;

/**
 * Notification Preferences as the contract frames them (spec 023, section 5):
 * `version`, `categories`, `quiet_hours` with `HH:MM`, `updated_at`.
 */
final class NotificationPreferencesResource
{
    /**
     * @return array{version: int, categories: array<string, bool>, quiet_hours: array{enabled: bool, start: string, end: string}, updated_at: ?string}
     */
    public static function toArray(Preferences $preferences): array
    {
        return [
            'version' => $preferences->version,
            'categories' => $preferences->categories,
            'quiet_hours' => [
                'enabled' => $preferences->quietHours->enabled,
                'start' => QuietHours::formatMinutes($preferences->quietHours->startMinutes),
                'end' => QuietHours::formatMinutes($preferences->quietHours->endMinutes),
            ],
            'updated_at' => $preferences->updatedAt === null ? null : PushRegistrationResource::instant($preferences->updatedAt),
        ];
    }
}
