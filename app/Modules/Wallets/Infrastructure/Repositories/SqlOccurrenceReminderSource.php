<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceReminderSource;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlOccurrenceReminderSource implements OccurrenceReminderSource
{
    public function needingAttention(DateTimeImmutable $now, ?string $afterId, int $limit): array
    {
        /** @var list<object{occurrence_id: string, wallet_id: string, kind: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT o.id AS occurrence_id, o.wallet_id,
                   CASE WHEN o.auto_confirm_refusal IS NOT NULL THEN 'refused' ELSE 'due' END AS kind
            FROM occurrences o
            JOIN recurring_rules r ON r.id = o.recurring_rule_id
            JOIN wallets w ON w.id = o.wallet_id
            WHERE o.status = 'scheduled'
              AND o.deleted_at IS NULL
              AND r.deleted_at IS NULL
              AND r.status = 'active'
              AND w.deleted_at IS NULL
              AND (?::text IS NULL OR o.id > ?::uuid)
              AND (
                    (r.auto_confirm = false
                       AND o.auto_confirm_refusal IS NULL
                       AND o.scheduled_date >= ((?::timestamptz AT TIME ZONE r.timezone)::date - 1)
                       AND o.scheduled_date <= (?::timestamptz AT TIME ZONE r.timezone)::date)
                 OR (o.auto_confirm_refusal IS NOT NULL
                       AND o.scheduled_date >= ((?::timestamptz AT TIME ZONE r.timezone)::date - 2))
              )
            ORDER BY o.id
            LIMIT ?
            SQL, [$afterId, $afterId, $at = $now->format('Y-m-d H:i:s.uP'), $at, $at, $limit]);

        return array_map(fn (object $row): array => [
            'occurrence_id' => (string) $row->occurrence_id,
            'wallet_id' => (string) $row->wallet_id,
            'kind' => (string) $row->kind,
        ], $rows);
    }

    public function membersOf(array $walletIds, array $roles): array
    {
        if ($walletIds === [] || $roles === []) {
            return [];
        }

        $members = [];

        $rows = DB::table('wallet_members')
            ->whereIn('wallet_id', $walletIds)
            ->whereNull('deleted_at')
            ->whereIn('role', array_map(fn (Role $role): string => $role->value, $roles))
            ->orderBy('id')
            ->get(['wallet_id', 'user_id']);

        foreach ($rows as $row) {
            $members[(string) $row->wallet_id][] = (string) $row->user_id;
        }

        return $members;
    }
}
