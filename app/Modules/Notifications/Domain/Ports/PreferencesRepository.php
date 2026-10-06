<?php

namespace App\Modules\Notifications\Domain\Ports;

use App\Modules\Notifications\Domain\Preferences\Preferences;

interface PreferencesRepository
{
    /**
     * The saved preferences; null when the person never saved.
     */
    public function find(string $userId): ?Preferences;

    /**
     * As find(), taking the row lock.
     */
    public function findForUpdate(string $userId): ?Preferences;

    /**
     * Inserts the first saved row.
     *
     * @return bool false when another save inserted it first
     */
    public function insertIfAbsent(string $userId, Preferences $preferences): bool;

    public function update(string $userId, Preferences $preferences): void;

    public function deleteFor(string $userId): int;
}
