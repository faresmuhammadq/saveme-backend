<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentRecurringRuleRepository implements RecurringRuleRepository
{
    public function find(string $ruleId): ?RecurringRuleRecord
    {
        return RecurringRule::query()->find($ruleId)?->toRecord();
    }

    public function findForUpdate(string $ruleId): ?RecurringRuleRecord
    {
        return RecurringRule::query()->whereKey($ruleId)->lockForUpdate()->first()?->toRecord();
    }

    public function isHeldByOther(string $ruleId, string $pathWalletId): bool
    {
        return Wallet::query()->whereKey($ruleId)->exists()
            || Category::query()->whereKey($ruleId)->exists()
            || CategoryReference::query()->whereKey($ruleId)->exists()
            || WalletMember::query()->whereKey($ruleId)->exists()
            || Transaction::query()->whereKey($ruleId)->exists()
            || Occurrence::query()->whereKey($ruleId)->exists()
            || RecurringRule::query()->whereKey($ruleId)->where('wallet_id', '<>', $pathWalletId)->exists();
    }

    public function insert(string $ruleId, string $walletId, array $values, string $timezone, string $createdBy, string $autoConfirmFrom, int $changeSeq): void
    {
        RecurringRule::query()->forceCreate([
            'id' => $ruleId,
            'wallet_id' => $walletId,
            ...$values,
            'auto_confirm_from' => $autoConfirmFrom,
            'timezone' => $timezone,
            'created_by' => $createdBy,
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);
    }

    public function updateIfVersion(string $ruleId, int $expectedVersion, array $columns, int $changeSeq): bool
    {
        return RecurringRule::query()
            ->whereKey($ruleId)
            ->where('version', $expectedVersion)
            ->toBase()
            ->update([
                ...$columns,
                'version' => $expectedVersion + 1,
                'change_seq' => $changeSeq,
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    public function listLive(string $walletId, array $filters, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $column = $sort === 'start_date' ? 'start_date' : 'created_at';
        $cast = $sort === 'start_date' ? '?::date' : '?::timestamptz';
        $direction = $descending ? 'DESC' : 'ASC';

        $query = RecurringRule::query()->where('wallet_id', $walletId)->whereNull('deleted_at');

        foreach ($filters['status'] ?? [] as $condition) {
            $condition['op'] === 'in'
                ? $query->whereIn('status', explode(',', $condition['value']))
                : $query->where('status', $condition['value']);
        }

        if ($after !== null) {
            $query->whereRaw("({$column}, id) ".($descending ? '<' : '>')." ({$cast}, ?::uuid)", [$after['value'], $after['id']]);
        }

        return array_values($query
            ->orderByRaw("{$column} {$direction}, id {$direction}")
            ->limit($limit + 1)
            ->get()
            ->map(fn (RecurringRule $rule): RecurringRuleRecord => $rule->toRecord())
            ->all());
    }

    public function sortValue(RecurringRuleRecord $rule, string $sort): string
    {
        return $sort === 'start_date'
            ? $rule->startDate
            : $rule->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public function nextForJob(string $afterId, int $limit): array
    {
        return array_values(RecurringRule::query()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->when($afterId !== '', fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (RecurringRule $rule): RecurringRuleRecord => $rule->toRecord())
            ->all());
    }

    public function liveInWallet(string $walletId): array
    {
        return array_values(RecurringRule::query()
            ->where('wallet_id', $walletId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (RecurringRule $rule): RecurringRuleRecord => $rule->toRecord())
            ->all());
    }

    public function categoryUsed(string $categoryId): bool
    {
        return RecurringRule::query()->where(fn ($query) => $query->where('category_id', $categoryId)->orWhere('payout_category_id', $categoryId))->exists();
    }

    public function categoriesUsedAmong(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $array = '{'.implode(',', $categoryIds).'}';

        return array_values(array_map(
            fn (object $row): string => (string) ((array) $row)['category_id'],
            DB::select(
                'SELECT category_id FROM recurring_rules WHERE category_id = ANY(?::uuid[])
                 UNION
                 SELECT payout_category_id FROM recurring_rules WHERE payout_category_id = ANY(?::uuid[])',
                [$array, $array],
            ),
        ));
    }

    public function anyInWalletUsing(string $walletId, string $categoryId): bool
    {
        return RecurringRule::query()
            ->where('wallet_id', $walletId)
            ->where(fn ($query) => $query->where('category_id', $categoryId)->orWhere('payout_category_id', $categoryId))
            ->exists();
    }
}
