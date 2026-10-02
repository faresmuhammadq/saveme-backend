<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\ChangeSequence;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Infrastructure\Models\Profile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EloquentProfileRepository implements ProfileRepository
{
    public function __construct(private readonly ChangeSequence $sequence) {}

    public function create(string $userId, string $locale, string $timezone, ?string $sealedDisplayName): void
    {
        // One transaction: the user counter's row lock lasts until the profile row is written.
        DB::transaction(fn () => Profile::query()->create([
            'user_id' => $userId,
            'locale' => $locale,
            'timezone' => $timezone,
            'display_name_ciphertext' => $sealedDisplayName,
            'version' => 1,
            'change_seq' => $this->sequence->next($userId),
        ]));
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
        // One transaction: the user counter's row lock lasts until the profile row is written,
        // and a lost version race rolls the stamp back so the counter has no hole.
        DB::beginTransaction();

        try {
            $saved = Profile::query()
                ->where('user_id', $userId)
                ->where('version', $expectedVersion)
                ->toBase()
                ->update([
                    ...$columns,
                    'version' => $expectedVersion + 1,
                    'change_seq' => $this->sequence->next($userId),
                    'updated_at' => now()->format('Y-m-d H:i:s.uP'),
                ]) === 1;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $saved ? DB::commit() : DB::rollBack();

        return $saved;
    }

    public function sealedDisplayNames(array $userIds): array
    {
        $names = [];

        foreach (Profile::query()->whereIn('user_id', $userIds)->whereNotNull('display_name_ciphertext')->get(['user_id', 'display_name_ciphertext']) as $profile) {
            $names[$profile->user_id] = (string) $profile->display_name_ciphertext;
        }

        return $names;
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
