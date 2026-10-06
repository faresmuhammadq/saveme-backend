<?php

namespace App\Modules\Budgeting\Infrastructure\Repositories;

use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Domain\PeriodType;
use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentBudgetRepository implements BudgetRepository
{
    public function findOwned(string $budgetId, string $ownerId): ?BudgetRecord
    {
        $budget = Budget::query()->whereKey($budgetId)->where('user_id', $ownerId)->first();

        return $budget === null ? null : self::record($budget);
    }

    public function findOwnedForUpdate(string $budgetId, string $ownerId): ?BudgetRecord
    {
        $budget = Budget::query()->whereKey($budgetId)->where('user_id', $ownerId)->lockForUpdate()->first();

        return $budget === null ? null : self::record($budget);
    }

    public function isHeldByOther(string $budgetId, string $ownerId): bool
    {
        return Budget::query()->whereKey($budgetId)->where('user_id', '<>', $ownerId)->exists();
    }

    public function insert(string $budgetId, string $ownerId, array $values, int $changeSeq): void
    {
        Budget::query()->forceCreate([
            'id' => $budgetId,
            'user_id' => $ownerId,
            ...$values,
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);
    }

    public function updateIfVersion(string $budgetId, int $expectedVersion, array $columns, int $changeSeq): bool
    {
        return Budget::query()
            ->whereKey($budgetId)
            ->where('version', $expectedVersion)
            ->toBase()
            ->update([
                ...$columns,
                'version' => $expectedVersion + 1,
                'change_seq' => $changeSeq,
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    public function listLive(string $userId, bool $activeOnly, bool $descending, ?array $after, int $limit): array
    {
        $query = Budget::query()->where('user_id', $userId)->whereNull('deleted_at');

        if ($activeOnly) {
            $query->whereNull('archived_at');
        }

        if ($after !== null) {
            $query->whereRaw('(created_at, id) '.($descending ? '<' : '>').' (?::timestamptz, ?::uuid)', [$after['value'], $after['id']]);
        }

        return array_values($query
            ->orderBy('created_at', $descending ? 'desc' : 'asc')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->limit($limit + 1)
            ->get()
            ->map(fn (Budget $budget): BudgetRecord => self::record($budget))
            ->all());
    }

    public function activeMatching(string $userId, string $currency, ?string $categoryId): array
    {
        return array_values(Budget::query()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->where('currency', $currency)
            ->where(fn ($query) => $query->whereNull('category_id')->when($categoryId !== null, fn ($inner) => $inner->orWhere('category_id', $categoryId)))
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn (Budget $budget): BudgetRecord => self::record($budget))
            ->all());
    }

    public function sortValue(BudgetRecord $budget): string
    {
        return $budget->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public function changedFor(string $userId, int $after, int $limit): array
    {
        return array_values(Budget::query()
            ->where('user_id', $userId)
            ->where('change_seq', '>', $after)
            ->orderBy('change_seq')
            ->limit($limit)
            ->get()
            ->map(fn (Budget $budget): BudgetRecord => self::record($budget))
            ->all());
    }

    public function categoryUsed(string $categoryId): bool
    {
        return Budget::query()->where('category_id', $categoryId)->exists();
    }

    public function categoriesUsedAmong(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return array_values(array_map(
            'strval',
            Budget::query()->whereIn('category_id', $categoryIds)->distinct()->pluck('category_id')->all(),
        ));
    }

    public function nextExpiredTombstones(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return array_values(Budget::query()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $before->format('Y-m-d H:i:s.uP'))
            ->when($afterId !== '', fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->toBase()
            ->get(['id', 'user_id', 'change_seq'])
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'user_id' => (string) $row->user_id, 'change_seq' => (int) $row->change_seq])
            ->all());
    }

    public function deleteTombstones(array $ids, DateTimeImmutable $before): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<object{user_id: string, change_seq: int|string}> $rows */
        $rows = DB::select(
            'DELETE FROM budgets WHERE id IN ('.implode(',', array_fill(0, count($ids), '?::uuid')).') AND deleted_at IS NOT NULL AND deleted_at < ?::timestamptz RETURNING user_id, change_seq',
            [...$ids, $before->format('Y-m-d H:i:s.uP')],
        );

        return array_map(fn (object $row): array => ['user_id' => (string) $row->user_id, 'change_seq' => (int) $row->change_seq], $rows);
    }

    public static function record(Budget $budget): BudgetRecord
    {
        return new BudgetRecord(
            id: $budget->id,
            userId: $budget->user_id,
            categoryId: $budget->category_id,
            periodType: PeriodType::from($budget->period_type),
            amountMinor: $budget->amount_minor,
            currency: $budget->currency,
            archivedAt: $budget->archived_at?->toDateTimeImmutable(),
            version: $budget->version,
            changeSeq: $budget->change_seq,
            createdAt: $budget->created_at->toDateTimeImmutable(),
            updatedAt: $budget->updated_at->toDateTimeImmutable(),
            deletedAt: $budget->deleted_at?->toDateTimeImmutable(),
        );
    }
}
