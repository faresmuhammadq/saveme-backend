<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Recurring\OccurrencePlan;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EloquentOccurrenceRepository implements OccurrenceRepository
{
    public function find(string $occurrenceId): ?OccurrenceRecord
    {
        return Occurrence::query()->find($occurrenceId)?->toRecord();
    }

    public function findForUpdate(string $occurrenceId): ?OccurrenceRecord
    {
        return Occurrence::query()->whereKey($occurrenceId)->lockForUpdate()->first()?->toRecord();
    }

    public function lockLiveForRule(string $ruleId): array
    {
        return array_values(Occurrence::query()
            ->where('recurring_rule_id', $ruleId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (Occurrence $occurrence): OccurrenceRecord => $occurrence->toRecord())
            ->all());
    }

    public function existingKeys(string $ruleId, string $from, string $to): array
    {
        $keys = [];

        $rows = Occurrence::query()
            ->where('recurring_rule_id', $ruleId)
            ->whereBetween('scheduled_date', [$from, $to])
            ->toBase()
            ->get(['id', 'scheduled_date', 'is_payout', 'deleted_at', 'status']);

        foreach ($rows as $row) {
            $keys[$row->scheduled_date.'|'.(filter_var($row->is_payout, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')] = [
                'id' => (string) $row->id,
                'deleted' => $row->deleted_at !== null,
                'status' => (string) $row->status,
            ];
        }

        return $keys;
    }

    public function insertMany(string $ruleId, string $walletId, array $plans, int $firstChangeSeq): int
    {
        if ($plans === []) {
            return 0;
        }

        $now = now()->format('Y-m-d H:i:s.uP');
        $rows = [];

        foreach ($plans as $index => $plan) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'wallet_id' => $walletId,
                'recurring_rule_id' => $ruleId,
                'ordinal' => $plan->ordinal,
                'is_payout' => $plan->isPayout,
                'scheduled_date' => $plan->scheduledDate,
                'date' => $plan->scheduledDate,
                'status' => 'scheduled',
                'kind' => $plan->kind->value,
                'amount_minor' => $plan->amountMinor,
                'currency' => $plan->currency,
                'category_id' => $plan->categoryId,
                'counter_wallet_id' => $plan->counterWalletId,
                'description' => $plan->description,
                'memo' => $plan->memo,
                'counterparty' => $plan->counterparty,
                'edited' => false,
                'transaction_id' => null,
                'auto_confirm_refusal' => null,
                'version' => 1,
                'change_seq' => $firstChangeSeq + $index,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];
        }

        return Occurrence::query()->insertOrIgnore($rows);
    }

    public function updateIfVersion(string $occurrenceId, int $expectedVersion, array $columns, int $changeSeq): bool
    {
        return Occurrence::query()
            ->whereKey($occurrenceId)
            ->where('version', $expectedVersion)
            ->toBase()
            ->update([
                ...$columns,
                'version' => $expectedVersion + 1,
                'change_seq' => $changeSeq,
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    public function updateMany(array $ids, array $columns, int $firstChangeSeq): void
    {
        if ($ids === []) {
            return;
        }

        $assignments = [];
        $bindings = [];

        foreach ($columns as $column => $value) {
            if (preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new InvalidArgumentException('Not a column name.');
            }

            $assignments[] = "{$column} = ?";
            $bindings[] = $value;
        }

        $assignments[] = 'version = o.version + 1';
        $assignments[] = 'updated_at = ?::timestamptz';
        $bindings[] = now()->format('Y-m-d H:i:s.uP');
        $assignments[] = 'change_seq = ?::bigint + s.rn - 1';
        $bindings[] = $firstChangeSeq;
        $bindings[] = '{'.implode(',', $ids).'}';

        DB::update(
            'UPDATE occurrences o SET '.implode(', ', $assignments).'
             FROM (SELECT id, row_number() OVER (ORDER BY id) AS rn FROM occurrences WHERE id = ANY(?::uuid[])) s
             WHERE o.id = s.id',
            $bindings,
        );
    }

    public function hasResolved(string $ruleId): bool
    {
        return Occurrence::query()->where('recurring_rule_id', $ruleId)->whereNull('deleted_at')->where('status', '<>', 'scheduled')->exists();
    }

    public function latestResolved(string $ruleId): ?array
    {
        $row = Occurrence::query()
            ->where('recurring_rule_id', $ruleId)
            ->whereNull('deleted_at')
            ->where('status', '<>', 'scheduled')
            ->toBase()
            ->selectRaw('max(ordinal) AS ordinal, max(scheduled_date) AS scheduled_date')
            ->first();

        return $row === null || $row->ordinal === null ? null : ['ordinal' => (int) $row->ordinal, 'scheduled_date' => (string) $row->scheduled_date];
    }

    public function dueForAutoConfirm(string $ruleId, string $from, string $today, int $limit): array
    {
        return array_values($this->dueQuery($ruleId, $from, $today, $limit)
            ->lockForUpdate()
            ->get()
            ->map(fn (Occurrence $occurrence): OccurrenceRecord => $occurrence->toRecord())
            ->all());
    }

    public function hasDueForAutoConfirm(string $ruleId, string $from, string $today): bool
    {
        return $this->dueQuery($ruleId, $from, $today, 1)->exists();
    }

    public function dueCategoryIds(string $ruleId, string $from, string $today, int $limit): array
    {
        $ids = [];

        foreach ($this->dueQuery($ruleId, $from, $today, $limit)->toBase()->get(['category_id']) as $row) {
            if ($row->category_id !== null) {
                $ids[(string) $row->category_id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return Builder<Occurrence>
     */
    private function dueQuery(string $ruleId, string $from, string $today, int $limit): Builder
    {
        return Occurrence::query()
            ->where('recurring_rule_id', $ruleId)
            ->whereNull('deleted_at')
            ->where('status', 'scheduled')
            ->whereNull('auto_confirm_refusal')
            ->where('date', '>=', $from)
            ->where('date', '<=', $today)
            ->orderBy('date')
            ->orderBy('id')
            ->limit($limit);
    }

    public function isIdTaken(string $id): bool
    {
        return Wallet::query()->whereKey($id)->exists()
            || Category::query()->whereKey($id)->exists()
            || CategoryReference::query()->whereKey($id)->exists()
            || WalletMember::query()->whereKey($id)->exists()
            || Transaction::query()->whereKey($id)->exists()
            || RecurringRule::query()->whereKey($id)->exists()
            || Occurrence::query()->whereKey($id)->exists();
    }

    public function listLive(string $walletId, array $filters, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $column = $sort === 'date' ? 'date' : 'created_at';
        $cast = $sort === 'date' ? '?::date' : '?::timestamptz';
        $direction = $descending ? 'DESC' : 'ASC';

        $query = Occurrence::query()->where('wallet_id', $walletId)->whereNull('deleted_at');

        foreach ($filters['recurring_rule_id'] ?? [] as $condition) {
            $condition['op'] === 'in'
                ? $query->whereIn('recurring_rule_id', explode(',', $condition['value']))
                : $query->where('recurring_rule_id', $condition['value']);
        }

        foreach ($filters['status'] ?? [] as $condition) {
            $condition['op'] === 'in'
                ? $query->whereIn('status', explode(',', $condition['value']))
                : $query->where('status', $condition['value']);
        }

        foreach ($filters['date'] ?? [] as $condition) {
            $query->where('date', ['eq' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$condition['op']], $condition['value']);
        }

        if ($after !== null) {
            $query->whereRaw("({$column}, id) ".($descending ? '<' : '>')." ({$cast}, ?::uuid)", [$after['value'], $after['id']]);
        }

        return array_values($query
            ->orderByRaw("{$column} {$direction}, id {$direction}")
            ->limit($limit + 1)
            ->get()
            ->map(fn (Occurrence $occurrence): OccurrenceRecord => $occurrence->toRecord())
            ->all());
    }

    public function sortValue(OccurrenceRecord $occurrence, string $sort): string
    {
        return $sort === 'date'
            ? $occurrence->date
            : $occurrence->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public function liveInWallet(string $walletId): array
    {
        return array_values(Occurrence::query()
            ->where('wallet_id', $walletId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (Occurrence $occurrence): OccurrenceRecord => $occurrence->toRecord())
            ->all());
    }

    public function categoryUsed(string $categoryId): bool
    {
        return Occurrence::query()->where('category_id', $categoryId)->exists();
    }

    public function categoriesUsedAmong(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return array_values(Occurrence::query()
            ->whereRaw('category_id = ANY(?::uuid[])', ['{'.implode(',', $categoryIds).'}'])
            ->distinct()
            ->pluck('category_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    public function anyInWalletUsing(string $walletId, string $categoryId): bool
    {
        return Occurrence::query()->where('wallet_id', $walletId)->where('category_id', $categoryId)->exists();
    }

    public function revive(string $occurrenceId, OccurrencePlan $plan, int $changeSeq): void
    {
        Occurrence::query()
            ->whereKey($occurrenceId)
            ->toBase()
            ->update([
                'deleted_at' => null,
                'ordinal' => $plan->ordinal,
                'date' => $plan->scheduledDate,
                'amount_minor' => $plan->amountMinor,
                'category_id' => $plan->categoryId,
                'description' => $plan->description,
                'memo' => $plan->memo,
                'counterparty' => $plan->counterparty,
                'edited' => false,
                'auto_confirm_refusal' => null,
                'version' => DB::raw('version + 1'),
                'change_seq' => $changeSeq,
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
    }
}
