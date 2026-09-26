<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\ChangeSequence;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Infrastructure\Models\Profile;

final class EloquentProfileRepository implements ProfileRepository
{
    public function __construct(private readonly ChangeSequence $sequence) {}

    public function create(string $userId, string $locale, string $timezone, ?string $sealedDisplayName): void
    {
        Profile::query()->create([
            'user_id' => $userId,
            'locale' => $locale,
            'timezone' => $timezone,
            'display_name_ciphertext' => $sealedDisplayName,
            'version' => 1,
            'change_seq' => $this->sequence->next(),
        ]);
    }

    public function localeOf(string $userId): string
    {
        return (string) (Profile::query()->where('user_id', $userId)->value('locale') ?? config('api.supported_locales')[0]);
    }

    public function timezoneOf(string $userId): string
    {
        return (string) (Profile::query()->where('user_id', $userId)->value('timezone') ?? config('identity.default_timezone'));
    }
}
