<?php

namespace App\Modules\Notifications\Infrastructure\Repositories;

use App\Modules\Notifications\Domain\Ports\RegistrationRepository;
use App\Modules\Notifications\Domain\Registration\PushRegistrationRecord;
use App\Modules\Notifications\Infrastructure\Models\PushRegistration;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentRegistrationRepository implements RegistrationRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function upsertForSession(string $sessionId, string $userId, string $tokenSealed, string $tokenLookup, DateTimeImmutable $now): PushRegistrationRecord
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($sessionId, $userId, $tokenSealed, $tokenLookup, $now): PushRegistrationRecord {
                    PushRegistration::query()
                        ->where('token_lookup', $tokenLookup)
                        ->where('session_id', '!=', $sessionId)
                        ->delete();

                    $at = $now->format(self::INSTANT);

                    DB::statement(
                        'INSERT INTO push_registrations (id, user_id, session_id, token_sealed, token_lookup, registered_at, refreshed_at, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON CONFLICT (session_id) DO UPDATE SET
                            user_id = EXCLUDED.user_id,
                            token_sealed = EXCLUDED.token_sealed,
                            token_lookup = EXCLUDED.token_lookup,
                            refreshed_at = EXCLUDED.refreshed_at,
                            updated_at = EXCLUDED.updated_at',
                        [(string) Str::uuid7(), $userId, $sessionId, $tokenSealed, $tokenLookup, $at, $at, $at, $at],
                    );

                    return self::record(PushRegistration::query()->where('session_id', $sessionId)->firstOrFail());
                });
            } catch (UniqueConstraintViolationException $race) {
                // Two sessions claimed the same token at once: the loser retries once and then takes it.
                if ($attempt >= 2) {
                    throw $race;
                }
            }
        }
    }

    public function deleteForSession(string $sessionId): bool
    {
        return PushRegistration::query()->where('session_id', $sessionId)->delete() > 0;
    }

    public function deleteById(string $registrationId): void
    {
        PushRegistration::query()->whereKey($registrationId)->delete();
    }

    public function liveFor(string $userId): array
    {
        return array_values(PushRegistration::query()->where('user_id', $userId)->orderBy('registered_at')->orderBy('id')->get()
            ->map(fn (PushRegistration $row): PushRegistrationRecord => self::record($row))
            ->all());
    }

    public function deleteIdleBefore(DateTimeImmutable $cut): int
    {
        return PushRegistration::query()->where('refreshed_at', '<', $cut->format(self::INSTANT))->delete();
    }

    public function deleteAllFor(string $userId): int
    {
        return PushRegistration::query()->where('user_id', $userId)->delete();
    }

    public function countFor(string $userId): int
    {
        return PushRegistration::query()->where('user_id', $userId)->count();
    }

    private static function record(PushRegistration $row): PushRegistrationRecord
    {
        return new PushRegistrationRecord(
            $row->id,
            $row->user_id,
            $row->session_id,
            $row->token_sealed,
            $row->registered_at->toDateTimeImmutable(),
            $row->refreshed_at->toDateTimeImmutable(),
        );
    }
}
