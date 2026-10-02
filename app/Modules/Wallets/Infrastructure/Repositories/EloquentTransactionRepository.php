<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Domain\Transactions\TransactionRecord;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentTransactionRepository implements TransactionRepository
{
    public function find(string $transactionId): ?TransactionRecord
    {
        return Transaction::query()->find($transactionId)?->toRecord();
    }

    public function exists(string $transactionId): bool
    {
        return Transaction::query()->whereKey($transactionId)->exists()
            || RecurringRule::query()->whereKey($transactionId)->exists()
            || Occurrence::query()->whereKey($transactionId)->exists();
    }

    public function findForUpdate(string $transactionId): ?TransactionRecord
    {
        return Transaction::query()->whereKey($transactionId)->lockForUpdate()->first()?->toRecord();
    }

    public function isHeldByOther(string $transactionId, string $pathWalletId): bool
    {
        return Wallet::query()->whereKey($transactionId)->exists()
            || Category::query()->whereKey($transactionId)->exists()
            || CategoryReference::query()->whereKey($transactionId)->exists()
            || WalletMember::query()->whereKey($transactionId)->exists()
            || RecurringRule::query()->whereKey($transactionId)->exists()
            || Occurrence::query()->whereKey($transactionId)->exists()
            || Transaction::query()
                ->whereKey($transactionId)
                ->where('wallet_id', '<>', $pathWalletId)
                ->where(fn (Builder $query) => $query->whereNull('counter_wallet_id')->orWhere('counter_wallet_id', '<>', $pathWalletId))
                ->exists();
    }

    public function insert(string $transactionId, string $walletId, array $values, string $createdBy, int $changeSeq, ?int $counterChangeSeq, ?string $recurringRuleId = null, ?string $occurrenceId = null): void
    {
        Transaction::query()->forceCreate([
            'id' => $transactionId,
            'wallet_id' => $walletId,
            ...$values,
            'created_by' => $createdBy,
            'version' => 1,
            'change_seq' => $changeSeq,
            'counter_change_seq' => $counterChangeSeq,
            'recurring_rule_id' => $recurringRuleId,
            'occurrence_id' => $occurrenceId,
        ]);
    }

    public function updateIfVersion(string $transactionId, int $expectedVersion, array $columns, ?int $changeSeq, ?int $counterChangeSeq): bool
    {
        $stamps = [];

        if ($changeSeq !== null) {
            $stamps['change_seq'] = $changeSeq;
        }

        if ($counterChangeSeq !== null) {
            $stamps['counter_change_seq'] = $counterChangeSeq;
        }

        return Transaction::query()
            ->whereKey($transactionId)
            ->where('version', $expectedVersion)
            ->toBase()
            ->update([
                ...$columns,
                ...$stamps,
                'version' => $expectedVersion + 1,
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    public function anyInWalletUsing(string $walletId, string $categoryId, string $exceptTransactionId): bool
    {
        return Transaction::query()
            ->where('wallet_id', $walletId)
            ->where('category_id', $categoryId)
            ->whereKeyNot($exceptTransactionId)
            ->exists();
    }

    public function categoryUsed(string $categoryId): bool
    {
        return Transaction::query()->where('category_id', $categoryId)->exists();
    }

    public function categoriesUsedAmong(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return array_values(Transaction::query()
            ->whereRaw('category_id = ANY(?::uuid[])', ['{'.implode(',', $categoryIds).'}'])
            ->distinct()
            ->pluck('category_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    public function listLive(string $walletId, array $filters, ?string $search, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $column = $sort === 'date' ? 'date' : 'created_at';
        $cast = $sort === 'date' ? '?::date' : '?::timestamptz';
        $direction = $descending ? 'DESC' : 'ASC';

        $query = Transaction::query()
            ->whereNull('deleted_at')
            ->where(fn (Builder $inner) => $inner->where('wallet_id', $walletId)->orWhere('counter_wallet_id', $walletId));

        foreach ($filters['date'] ?? [] as $condition) {
            $query->where('date', ['eq' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$condition['op']], $condition['value']);
        }

        foreach ($filters['category_id'] ?? [] as $condition) {
            $condition['op'] === 'in'
                ? $query->whereIn('category_id', explode(',', $condition['value']))
                : $query->where('category_id', $condition['value']);
        }

        foreach ($filters['kind'] ?? [] as $condition) {
            $condition['op'] === 'in'
                ? $query->whereIn('kind', explode(',', $condition['value']))
                : $query->where('kind', $condition['value']);
        }

        if ($search !== null) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->whereRaw("(description ILIKE ? ESCAPE '\\' OR memo ILIKE ? ESCAPE '\\' OR counterparty ILIKE ? ESCAPE '\\')", [$pattern, $pattern, $pattern]);
        }

        if ($after !== null) {
            $query->whereRaw("({$column}, id) ".($descending ? '<' : '>')." ({$cast}, ?::uuid)", [$after['value'], $after['id']]);
        }

        return array_values($query
            ->orderByRaw("{$column} {$direction}, id {$direction}")
            ->limit($limit + 1)
            ->toBase()
            ->get()
            ->map(fn (object $row): TransactionRecord => self::rawRecord($row)) // @phpstan-ignore argument.type
            ->all());
    }

    public function sortValue(TransactionRecord $transaction, string $sort): string
    {
        return $sort === 'date'
            ? $transaction->date
            : $transaction->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public function anyLiveRecordedBy(string $userId): bool
    {
        return Transaction::query()->where('created_by', $userId)->whereNull('deleted_at')->exists();
    }

    public function liveGoingWith(string $walletId): array
    {
        return array_values(Transaction::query()
            ->whereNull('deleted_at')
            ->where(fn (Builder $inner) => $inner->where('wallet_id', $walletId)->orWhere('counter_wallet_id', $walletId))
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM wallets other WHERE other.deleted_at IS NULL AND other.id = (CASE WHEN transactions.wallet_id = ? THEN transactions.counter_wallet_id ELSE transactions.wallet_id END))',
                [$walletId],
            )
            ->get()
            ->map(fn (Transaction $transaction): TransactionRecord => $transaction->toRecord())
            ->all());
    }

    /**
     * A record from a plain row: a page of 50 skips Eloquent's per-row
     * hydration and casting (SC-007).
     *
     * @param  object{id: string, wallet_id: string, kind: string, date: string, amount_minor: int|string, currency: string, category_id: ?string, counter_wallet_id: ?string, description: ?string, memo: ?string, counterparty: ?string, cleared: bool|int|string, created_by: string, version: int|string, change_seq: int|string, counter_change_seq: int|string|null, created_at: string, updated_at: string, deleted_at: ?string, recurring_rule_id?: ?string, occurrence_id?: ?string}  $row
     */
    public static function rawRecord(object $row): TransactionRecord
    {
        return new TransactionRecord(
            id: $row->id,
            walletId: $row->wallet_id,
            kind: TransactionKind::from($row->kind),
            date: $row->date,
            amountMinor: (int) $row->amount_minor,
            currency: $row->currency,
            categoryId: $row->category_id,
            counterWalletId: $row->counter_wallet_id,
            description: $row->description,
            memo: $row->memo,
            counterparty: $row->counterparty,
            cleared: filter_var($row->cleared, FILTER_VALIDATE_BOOLEAN),
            createdBy: $row->created_by,
            version: (int) $row->version,
            changeSeq: (int) $row->change_seq,
            counterChangeSeq: $row->counter_change_seq === null ? null : (int) $row->counter_change_seq,
            createdAt: new DateTimeImmutable($row->created_at),
            updatedAt: new DateTimeImmutable($row->updated_at),
            deletedAt: $row->deleted_at === null ? null : new DateTimeImmutable($row->deleted_at),
            recurringRuleId: $row->recurring_rule_id ?? null,
            occurrenceId: $row->occurrence_id ?? null,
        );
    }

    public function expenseTotalsRecordedBy(string $userId, string $from, string $to): array
    {
        /** @var list<object{category_id: string, currency: string, amount_minor: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT p.category_id, p.currency, SUM(p.amount_minor)::bigint AS amount_minor
            FROM transactions t
            JOIN postings p ON p.transaction_id = t.id AND p.category_id IS NOT NULL
            JOIN wallets w ON w.id = t.wallet_id AND w.deleted_at IS NULL
            JOIN wallet_members m ON m.wallet_id = t.wallet_id AND m.user_id = :member AND m.deleted_at IS NULL
            WHERE t.created_by = :author
              AND t.deleted_at IS NULL
              AND t.kind = 'expense'
              AND t.date BETWEEN :from AND :to
            GROUP BY p.category_id, p.currency
            SQL, ['member' => $userId, 'author' => $userId, 'from' => $from, 'to' => $to]);

        return array_map(
            fn (object $row): array => ['category_id' => (string) $row->category_id, 'currency' => (string) $row->currency, 'amount_minor' => (int) $row->amount_minor],
            $rows,
        );
    }

    public function identifierTaken(string $id): bool
    {
        return Wallet::query()->whereKey($id)->exists()
            || Category::query()->whereKey($id)->exists()
            || CategoryReference::query()->whereKey($id)->exists()
            || WalletMember::query()->whereKey($id)->exists()
            || Transaction::query()->whereKey($id)->exists()
            || RecurringRule::query()->whereKey($id)->exists()
            || Occurrence::query()->whereKey($id)->exists();
    }
}
