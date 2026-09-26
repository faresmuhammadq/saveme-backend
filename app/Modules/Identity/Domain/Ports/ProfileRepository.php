<?php

namespace App\Modules\Identity\Domain\Ports;

interface ProfileRepository
{
    public function create(string $userId, string $locale, string $timezone, ?string $sealedDisplayName): void;

    public function localeOf(string $userId): string;

    public function timezoneOf(string $userId): string;
}
