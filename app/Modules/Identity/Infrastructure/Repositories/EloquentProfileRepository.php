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

    public function findSealed(string $userId): array
    {
        $profile = Profile::query()->where('user_id', $userId)->firstOrFail();

        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'display_name_ciphertext' => $profile->display_name_ciphertext,
            'locale' => $profile->locale,
            'timezone' => $profile->timezone,
            'default_currency' => $profile->default_currency,
            'budget_period_start_day' => $profile->budget_period_start_day,
            'budget_period_start_day_from' => $profile->budget_period_start_day_from?->format('Y-m-d'),
            'version' => $profile->version,
            'change_seq' => $profile->change_seq,
            'created_at' => $profile->created_at->toDateTimeImmutable(),
            'updated_at' => $profile->updated_at->toDateTimeImmutable(),
        ];
    }

    public function saveIfVersion(string $userId, int $expectedVersion, array $columns): bool
    {
        return Profile::query()
            ->where('user_id', $userId)
            ->where('version', $expectedVersion)
            ->toBase()
            ->update([
                ...$columns,
                'version' => $expectedVersion + 1,
                'change_seq' => $this->sequence->next(),
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
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
