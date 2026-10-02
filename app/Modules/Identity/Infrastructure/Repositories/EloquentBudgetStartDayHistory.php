<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\BudgetStartDayHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentBudgetStartDayHistory implements BudgetStartDayHistory
{
    public function entriesOf(string $userId): array
    {
        return array_values(DB::table('budget_start_days')
            ->where('user_id', $userId)
            ->orderByRaw('applies_from ASC NULLS FIRST')
            ->get(['start_day', 'applies_from'])
            ->map(fn (object $row): array => ['start_day' => (int) $row->start_day, 'applies_from' => $row->applies_from === null ? null : (string) $row->applies_from])
            ->all());
    }

    public function record(string $userId, int $previousDay, int $newDay, string $appliesFrom, string $today): void
    {
        $now = now()->format('Y-m-d H:i:s.uP');

        if (! DB::table('budget_start_days')->where('user_id', $userId)->exists()) {
            DB::table('budget_start_days')->insert(['id' => (string) Str::uuid7(), 'user_id' => $userId, 'start_day' => $previousDay, 'applies_from' => null, 'created_at' => $now]);
        }

        DB::table('budget_start_days')->where('user_id', $userId)->where('applies_from', '>', $today)->delete();
        DB::table('budget_start_days')->insert(['id' => (string) Str::uuid7(), 'user_id' => $userId, 'start_day' => $newDay, 'applies_from' => $appliesFrom, 'created_at' => $now]);
    }

    public function currentDayOf(string $userId): int
    {
        return (int) (DB::table('profiles')->where('user_id', $userId)->value('budget_period_start_day') ?? 1);
    }
}
