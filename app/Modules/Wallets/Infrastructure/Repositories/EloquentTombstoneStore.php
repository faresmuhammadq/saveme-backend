<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Ports\TombstoneStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentTombstoneStore implements TombstoneStore
{
    private const NIL = '00000000-0000-0000-0000-000000000000';

    public function nextExpiredTransactions(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id, wallet_id, counter_wallet_id FROM transactions WHERE deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::instant($before), self::after($afterId), $limit],
        );

        return array_map(fn (array $row): array => ['id' => (string) $row['id'], 'wallet_id' => (string) $row['wallet_id'], 'counter_wallet_id' => $row['counter_wallet_id'] === null ? null : (string) $row['counter_wallet_id']], $rows);
    }

    public function nextExpiredOccurrences(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id, wallet_id FROM occurrences WHERE deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::instant($before), self::after($afterId), $limit],
        );

        return array_map(fn (array $row): array => ['id' => (string) $row['id'], 'wallet_id' => (string) $row['wallet_id']], $rows);
    }

    public function nextExpiredRules(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id, wallet_id FROM recurring_rules WHERE deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::instant($before), self::after($afterId), $limit],
        );

        return array_map(fn (array $row): array => ['id' => (string) $row['id'], 'wallet_id' => (string) $row['wallet_id']], $rows);
    }

    public function nextExpiredReferences(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id, wallet_id FROM category_references WHERE deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::instant($before), self::after($afterId), $limit],
        );

        return array_map(fn (array $row): array => ['id' => (string) $row['id'], 'wallet_id' => (string) $row['wallet_id']], $rows);
    }

    public function nextExpiredMembers(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id, wallet_id FROM wallet_members WHERE deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::instant($before), self::after($afterId), $limit],
        );

        return array_map(fn (array $row): array => ['id' => (string) $row['id'], 'wallet_id' => (string) $row['wallet_id']], $rows);
    }

    public function nextExpiredCategories(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id, user_id FROM categories WHERE user_id IS NOT NULL AND deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::instant($before), self::after($afterId), $limit],
        );

        return array_map(fn (array $row): array => ['id' => (string) $row['id'], 'user_id' => (string) $row['user_id']], $rows);
    }

    public function nextExpiredWallets(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id FROM wallets WHERE deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::instant($before), self::after($afterId), $limit],
        );

        return array_map(fn (array $row): string => (string) $row['id'], $rows);
    }

    public function lockWallets(array $walletIds): void
    {
        if ($walletIds === []) {
            return;
        }

        self::select('SELECT id FROM wallets WHERE id = ANY(?::uuid[]) ORDER BY id FOR UPDATE', [self::uuidArray($walletIds)]);
    }

    public function lockCategories(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        self::select('SELECT id FROM categories WHERE id = ANY(?::uuid[]) ORDER BY id FOR UPDATE', [self::uuidArray($categoryIds)]);
    }

    public function deleteTransactions(array $ids, DateTimeImmutable $before): array
    {
        $rows = self::select(
            'DELETE FROM transactions t WHERE t.id = ANY(?::uuid[]) AND t.deleted_at < ?::timestamptz
               AND NOT EXISTS (SELECT 1 FROM postings p WHERE p.transaction_id = t.id)
             RETURNING t.wallet_id, t.counter_wallet_id, t.change_seq, t.counter_change_seq',
            [self::uuidArray($ids), self::instant($before)],
        );

        return array_map(fn (array $row): array => [
            'wallet_id' => (string) $row['wallet_id'],
            'counter_wallet_id' => $row['counter_wallet_id'] === null ? null : (string) $row['counter_wallet_id'],
            'change_seq' => (int) $row['change_seq'],
            'counter_change_seq' => $row['counter_change_seq'] === null ? null : (int) $row['counter_change_seq'],
        ], $rows);
    }

    public function deleteOccurrences(array $ids, DateTimeImmutable $before): array
    {
        $rows = self::select(
            'DELETE FROM occurrences o WHERE o.id = ANY(?::uuid[]) AND o.deleted_at < ?::timestamptz
               AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.occurrence_id = o.id)
             RETURNING o.wallet_id, o.change_seq',
            [self::uuidArray($ids), self::instant($before)],
        );

        return array_map(fn (array $row): array => ['wallet_id' => (string) $row['wallet_id'], 'change_seq' => (int) $row['change_seq']], $rows);
    }

    public function deleteRules(array $ids, DateTimeImmutable $before): array
    {
        $rows = self::select(
            'DELETE FROM recurring_rules r WHERE r.id = ANY(?::uuid[]) AND r.deleted_at < ?::timestamptz
               AND NOT EXISTS (SELECT 1 FROM occurrences o WHERE o.recurring_rule_id = r.id)
               AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.recurring_rule_id = r.id)
             RETURNING r.wallet_id, r.change_seq',
            [self::uuidArray($ids), self::instant($before)],
        );

        return array_map(fn (array $row): array => ['wallet_id' => (string) $row['wallet_id'], 'change_seq' => (int) $row['change_seq']], $rows);
    }

    public function deleteReferences(array $ids, DateTimeImmutable $before): array
    {
        $rows = self::select(
            'DELETE FROM category_references WHERE id = ANY(?::uuid[]) AND deleted_at < ?::timestamptz RETURNING wallet_id, change_seq',
            [self::uuidArray($ids), self::instant($before)],
        );

        return array_map(fn (array $row): array => ['wallet_id' => (string) $row['wallet_id'], 'change_seq' => (int) $row['change_seq']], $rows);
    }

    public function deleteMembers(array $ids, DateTimeImmutable $before): array
    {
        $rows = self::select(
            'DELETE FROM wallet_members WHERE id = ANY(?::uuid[]) AND deleted_at < ?::timestamptz RETURNING wallet_id, user_id, change_seq, member_change_seq',
            [self::uuidArray($ids), self::instant($before)],
        );

        return array_map(fn (array $row): array => [
            'wallet_id' => (string) $row['wallet_id'],
            'user_id' => (string) $row['user_id'],
            'change_seq' => (int) $row['change_seq'],
            'member_change_seq' => (int) $row['member_change_seq'],
        ], $rows);
    }

    public function deleteUnreferencedCategories(array $ids, DateTimeImmutable $before): array
    {
        $rows = self::select(
            'DELETE FROM categories c WHERE c.id = ANY(?::uuid[]) AND c.user_id IS NOT NULL AND c.deleted_at < ?::timestamptz
               AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM postings p WHERE p.category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM category_references r WHERE r.category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM recurring_rules rr WHERE rr.category_id = c.id OR rr.payout_category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM occurrences oc WHERE oc.category_id = c.id)
             RETURNING c.user_id, c.change_seq',
            [self::uuidArray($ids), self::instant($before)],
        );

        return array_map(fn (array $row): array => ['user_id' => (string) $row['user_id'], 'change_seq' => (int) $row['change_seq']], $rows);
    }

    public function nextArchivedCategories(string $afterId, int $limit): array
    {
        $rows = self::select(
            'SELECT id, user_id FROM categories WHERE user_id IS NOT NULL AND archived_at IS NOT NULL AND deleted_at IS NULL AND id > ?::uuid ORDER BY id LIMIT ?',
            [self::after($afterId), $limit],
        );

        return array_map(fn (array $row): array => ['id' => (string) $row['id'], 'user_id' => (string) $row['user_id']], $rows);
    }

    public function deleteUnreferencedArchivedCategories(array $ids): int
    {
        return DB::delete(
            'DELETE FROM categories c WHERE c.id = ANY(?::uuid[]) AND c.user_id IS NOT NULL AND c.archived_at IS NOT NULL AND c.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM postings p WHERE p.category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM category_references r WHERE r.category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM recurring_rules rr WHERE rr.category_id = c.id OR rr.payout_category_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM occurrences oc WHERE oc.category_id = c.id)',
            [self::uuidArray($ids)],
        );
    }

    public function deleteUnreferencedWallets(array $ids, DateTimeImmutable $before): int
    {
        return DB::delete(
            'DELETE FROM wallets w WHERE w.id = ANY(?::uuid[]) AND w.deleted_at < ?::timestamptz
               AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.wallet_id = w.id OR t.counter_wallet_id = w.id)
               AND NOT EXISTS (SELECT 1 FROM postings p WHERE p.wallet_id = w.id)
               AND NOT EXISTS (SELECT 1 FROM category_references r WHERE r.wallet_id = w.id)
               AND NOT EXISTS (SELECT 1 FROM wallet_members m WHERE m.wallet_id = w.id)
               AND NOT EXISTS (SELECT 1 FROM recurring_rules rr WHERE rr.wallet_id = w.id OR rr.counter_wallet_id = w.id)
               AND NOT EXISTS (SELECT 1 FROM occurrences oc WHERE oc.wallet_id = w.id OR oc.counter_wallet_id = w.id)',
            [self::uuidArray($ids), self::instant($before)],
        );
    }

    public function raiseWalletHorizon(string $walletId, int $position): void
    {
        DB::update('UPDATE wallets SET purged_change_seq = greatest(purged_change_seq, ?) WHERE id = ?', [$position, $walletId]);
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private static function select(string $sql, array $bindings): array
    {
        return array_values(array_map(fn (object $row): array => (array) $row, DB::select($sql, $bindings)));
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private static function after(string $afterId): string
    {
        return $afterId === '' ? self::NIL : $afterId;
    }

    /**
     * @param  list<string>  $ids
     */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
