<?php

namespace App\Modules\Identity\Infrastructure\Sync;

use App\Modules\Identity\Domain\Ports\UserScope;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Repositories\EloquentSessionRepository;
use Illuminate\Support\Facades\DB;

final class EloquentUserScope implements UserScope
{
    public function scopeOf(string $userId): ?array
    {
        $row = User::query()->whereKey($userId)->toBase()->first(['change_seq', 'purged_change_seq']);

        return $row === null ? null : ['counter' => (int) $row->change_seq, 'purged' => (int) $row->purged_change_seq];
    }

    public function sessionsChangedAfter(string $userId, int $after, int $limit): array
    {
        return array_values(DeviceSession::query()
            ->where('user_id', $userId)
            ->where('change_seq', '>', $after)
            ->orderBy('change_seq')
            ->limit($limit)
            ->get()
            ->map(fn (DeviceSession $session) => EloquentSessionRepository::record($session))
            ->all());
    }

    public function raiseHorizon(string $userId, int $position): void
    {
        DB::update('UPDATE users SET purged_change_seq = greatest(purged_change_seq, ?) WHERE id = ?', [$position, $userId]);
    }
}
