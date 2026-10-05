<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Identity\Domain\Ports\AccountPurgeRepository;
use App\Modules\Identity\Infrastructure\Models\AccountPurge;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentAccountPurgeRepository implements AccountPurgeRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function tryClaim(string $userId): bool
    {
        return (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS claimed', ['account_purge:'.$userId])->claimed;
    }

    public function release(string $userId): void
    {
        DB::selectOne('SELECT pg_advisory_unlock(hashtext(?))', ['account_purge:'.$userId]);
    }

    public function begin(string $userId, DateTimeImmutable $at): void
    {
        $now = $at->format(self::INSTANT);

        DB::statement(
            "INSERT INTO account_purges (user_id, started_at, last_step, attempts, counts, updated_at) VALUES (?, ?, 'begin', 1, '{}', ?)
             ON CONFLICT (user_id) DO UPDATE SET attempts = account_purges.attempts + 1, updated_at = excluded.updated_at",
            [$userId, $now, $now],
        );
    }

    public function find(string $userId): ?array
    {
        $row = AccountPurge::query()->find($userId);

        if ($row === null) {
            return null;
        }

        return [
            'last_step' => PurgeStep::from($row->last_step),
            'counts' => PurgeCounts::fromStored($row->counts),
            'started_at' => $row->started_at->toDateTimeImmutable(),
            'mail_sent_at' => $row->mail_sent_at?->toDateTimeImmutable(),
            'stall_notified_at' => $row->stall_notified_at?->toDateTimeImmutable(),
        ];
    }

    public function record(string $userId, PurgeStep $step, PurgeCounts $counts, DateTimeImmutable $at): void
    {
        AccountPurge::query()->whereKey($userId)->toBase()->update([
            'last_step' => $step->value,
            'counts' => json_encode($counts->toArray(), JSON_THROW_ON_ERROR),
            'updated_at' => $at->format(self::INSTANT),
        ]);
    }

    public function markMailSent(string $userId, DateTimeImmutable $at): bool
    {
        return AccountPurge::query()->whereKey($userId)->whereNull('mail_sent_at')->toBase()->update([
            'mail_sent_at' => $at->format(self::INSTANT),
        ]) === 1;
    }

    public function delete(string $userId): void
    {
        AccountPurge::query()->whereKey($userId)->delete();
    }

    public function stalledBefore(DateTimeImmutable $cut): array
    {
        return array_values(AccountPurge::query()
            ->where('started_at', '<', $cut->format(self::INSTANT))
            ->whereNull('stall_notified_at')
            ->orderBy('started_at')
            ->get()
            ->map(fn (AccountPurge $row): array => ['user_id' => $row->user_id, 'started_at' => $row->started_at->toDateTimeImmutable()])
            ->all());
    }

    public function markStallNotified(string $userId, DateTimeImmutable $at): bool
    {
        return AccountPurge::query()->whereKey($userId)->whereNull('stall_notified_at')->toBase()->update([
            'stall_notified_at' => $at->format(self::INSTANT),
        ]) === 1;
    }
}
