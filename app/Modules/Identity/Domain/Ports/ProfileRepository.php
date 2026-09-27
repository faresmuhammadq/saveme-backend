<?php

namespace App\Modules\Identity\Domain\Ports;

use DateTimeImmutable;

interface ProfileRepository
{
    public function create(string $userId, string $locale, string $timezone, ?string $sealedDisplayName): void;

    /**
     * The stored profile, with the display name still sealed.
     *
     * @return array{id: string, user_id: string, display_name_ciphertext: ?string, locale: string, timezone: string, default_currency: string, budget_period_start_day: int, budget_period_start_day_from: ?string, version: int, change_seq: int, created_at: DateTimeImmutable, updated_at: DateTimeImmutable}
     */
    public function findSealed(string $userId): array;

    /**
     * Writes the columns and bumps version and change_seq, only if the stored
     * version is still $expectedVersion (P-07).
     *
     * @param  array{display_name_ciphertext: ?string, locale: string, timezone: string, default_currency: string, budget_period_start_day: int, budget_period_start_day_from: ?string}  $columns
     * @return bool whether a row was written
     */
    public function saveIfVersion(string $userId, int $expectedVersion, array $columns): bool;

    public function localeOf(string $userId): string;

    public function timezoneOf(string $userId): string;
}
