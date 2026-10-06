<?php

namespace App\Modules\Budgeting\Infrastructure\Repositories;

use App\Modules\Budgeting\Domain\Ports\OverrunRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentOverrunRepository implements OverrunRepository
{
    public function recordOnce(string $budgetId, string $userId, string $periodStart, int $threshold, DateTimeImmutable $at): bool
    {
        $inserted = DB::selectOne(
            'INSERT INTO budget_overruns (id, budget_id, user_id, period_start, threshold, crossed_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (budget_id, period_start, threshold) DO NOTHING
             RETURNING id',
            [(string) Str::uuid7(), $budgetId, $userId, $periodStart, $threshold, $at->format('Y-m-d H:i:s.uP')],
        );

        return $inserted !== null;
    }
}
