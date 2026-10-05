<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\ChangeSequence;
use App\Modules\Identity\Domain\Ports\SessionRepository;
use App\Modules\Identity\Domain\Sessions\EndReason;
use App\Modules\Identity\Domain\Sessions\SessionRecord;
use App\Modules\Identity\Domain\Sessions\SessionState;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EloquentSessionRepository implements SessionRepository
{
    public function __construct(private readonly ChangeSequence $sequence) {}

    public function find(string $sessionId): ?SessionRecord
    {
        $session = DeviceSession::query()->find($sessionId);

        return $session === null ? null : self::record($session);
    }

    public function activeForDevice(string $userId, string $deviceId): ?SessionRecord
    {
        $session = DeviceSession::query()
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('state', SessionState::Active->value)
            ->first();

        return $session === null ? null : self::record($session);
    }

    public function end(string $sessionId, EndReason $reason): bool
    {
        $state = $reason === EndReason::Expired ? SessionState::Expired : SessionState::Revoked;

        $userId = DeviceSession::query()
            ->whereKey($sessionId)
            ->where('state', SessionState::Active->value)
            ->value('user_id');

        if ($userId === null) {
            return false;
        }

        // One transaction: the user counter's row lock lasts until the session row is written.
        DB::beginTransaction();

        try {
            $ended = DeviceSession::query()
                ->whereKey($sessionId)
                ->where('state', SessionState::Active->value)
                ->update([
                    'state' => $state->value,
                    'end_reason' => $reason->value,
                    'ended_at' => now()->format('Y-m-d H:i:s.uP'),
                    'version' => DB::raw('version + 1'),
                    'change_seq' => $this->sequence->next((string) $userId),
                    'updated_at' => now()->format('Y-m-d H:i:s.uP'),
                ]) === 1;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $ended ? DB::commit() : DB::rollBack();

        return $ended;
    }

    public function activeIdsFor(string $userId, ?string $except = null): array
    {
        /** @var list<string> $ids */
        $ids = DeviceSession::query()
            ->where('user_id', $userId)
            ->where('state', SessionState::Active->value)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->pluck('id')
            ->all();

        return $ids;
    }

    public function activeCountFor(string $userId, DateTimeImmutable $now): int
    {
        return DeviceSession::query()
            ->where('user_id', $userId)
            ->where('state', SessionState::Active->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhereRaw('expires_at > ?', [$now->format('Y-m-d H:i:s.uP')]))
            ->count();
    }

    public function page(string $userId, ?SessionState $state, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $direction = $descending ? 'desc' : 'asc';
        $comparison = $descending ? '<' : '>';
        // A never-used session sorts by its sign-in time, so null never breaks the keyset.
        $column = $sort === 'last_used_at' ? DB::raw('coalesce(last_used_at, signed_in_at)') : 'signed_in_at';
        $columnSql = $sort === 'last_used_at' ? 'coalesce(last_used_at, signed_in_at)' : 'signed_in_at';

        $query = DeviceSession::query()
            ->where('user_id', $userId)
            ->when($state !== null, fn ($q) => $q->where('state', $state?->value));

        if ($after !== null) {
            $query->whereRaw("({$columnSql}, id) {$comparison} (?::timestamptz, ?::uuid)", [$after['value'], $after['id']]);
        }

        return array_values($query->orderBy($column, $direction)->orderBy('id', $direction)
            ->limit($limit)
            ->get()
            ->map(fn (DeviceSession $session): SessionRecord => self::record($session))
            ->all());
    }

    public function expireIdleSince(DateTimeImmutable $idleSince): array
    {
        $expired = [];

        DeviceSession::query()
            ->where('state', SessionState::Active->value)
            ->whereRaw('coalesce(last_used_at, signed_in_at) <= ?', [$idleSince->format('Y-m-d H:i:s.uP')])
            ->select(['id', 'user_id'])
            ->each(function (DeviceSession $session) use (&$expired): void {
                if ($this->end($session->id, EndReason::Expired)) {
                    $expired[] = ['id' => $session->id, 'user_id' => $session->user_id];
                }
            });

        return $expired;
    }

    public static function record(DeviceSession $session): SessionRecord
    {
        return new SessionRecord(
            id: $session->id,
            userId: $session->user_id,
            deviceId: $session->device_id,
            deviceName: $session->device_name,
            platform: $session->platform,
            appVersion: $session->app_version,
            state: SessionState::from($session->state),
            endReason: $session->end_reason === null ? null : EndReason::from($session->end_reason),
            signedInAt: $session->signed_in_at->toDateTimeImmutable(),
            lastUsedAt: $session->last_used_at?->toDateTimeImmutable(),
            endedAt: $session->ended_at?->toDateTimeImmutable(),
            version: $session->version,
            changeSeq: $session->change_seq,
            createdAt: $session->created_at->toDateTimeImmutable(),
            updatedAt: $session->updated_at->toDateTimeImmutable(),
        );
    }
}
