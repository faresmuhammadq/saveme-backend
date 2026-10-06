<?php

namespace App\Modules\Notifications\Infrastructure\Repositories;

use App\Modules\Notifications\Domain\Ports\PreferencesRepository;
use App\Modules\Notifications\Domain\Preferences\Category;
use App\Modules\Notifications\Domain\Preferences\Preferences;
use App\Modules\Notifications\Domain\Preferences\QuietHours;
use App\Modules\Notifications\Infrastructure\Models\NotificationPreference;

final class EloquentPreferencesRepository implements PreferencesRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function find(string $userId): ?Preferences
    {
        $row = NotificationPreference::query()->find($userId);

        return $row === null ? null : self::preferences($row);
    }

    public function findForUpdate(string $userId): ?Preferences
    {
        $row = NotificationPreference::query()->whereKey($userId)->lockForUpdate()->first();

        return $row === null ? null : self::preferences($row);
    }

    public function insertIfAbsent(string $userId, Preferences $preferences): bool
    {
        $at = ($preferences->updatedAt ?? now()->toDateTimeImmutable())->format(self::INSTANT);

        return NotificationPreference::query()->toBase()->insertOrIgnore([
            'user_id' => $userId,
            ...$preferences->categories,
            ...self::quietColumns($preferences->quietHours),
            'version' => $preferences->version,
            'created_at' => $at,
            'updated_at' => $at,
        ]) === 1;
    }

    public function update(string $userId, Preferences $preferences): void
    {
        NotificationPreference::query()->whereKey($userId)->toBase()->update([
            ...$preferences->categories,
            ...self::quietColumns($preferences->quietHours),
            'version' => $preferences->version,
            'updated_at' => ($preferences->updatedAt ?? now()->toDateTimeImmutable())->format(self::INSTANT),
        ]);
    }

    public function deleteFor(string $userId): int
    {
        return NotificationPreference::query()->whereKey($userId)->delete();
    }

    /**
     * @return array{quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int}
     */
    private static function quietColumns(QuietHours $quiet): array
    {
        return [
            'quiet_hours_enabled' => $quiet->enabled,
            'quiet_hours_start' => $quiet->startMinutes,
            'quiet_hours_end' => $quiet->endMinutes,
        ];
    }

    private static function preferences(NotificationPreference $row): Preferences
    {
        $categories = [];

        foreach (Category::cases() as $category) {
            $categories[$category->value] = (bool) $row->getAttribute($category->value);
        }

        return new Preferences(
            $row->version,
            $categories,
            new QuietHours($row->quiet_hours_enabled, $row->quiet_hours_start, $row->quiet_hours_end),
            $row->updated_at->toDateTimeImmutable(),
        );
    }
}
