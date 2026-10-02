<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivityRecord;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\ActivityLog;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentActivityLog implements ActivityLog
{
    public function record(string $walletId, ?string $actorId, ActivityAction $action, ActivitySubject $subject, string $subjectId, ?Role $role): void
    {
        WalletActivity::query()->create([
            'id' => (string) Str::uuid7(),
            'wallet_id' => $walletId,
            'occurred_at' => now(),
            'actor_user_id' => $actorId,
            'action' => $action->value,
            'subject_type' => $subject->value,
            'subject_id' => $subjectId,
            'role' => $role?->value,
        ]);
    }

    public function page(string $walletId, ?array $after, int $limit): array
    {
        $query = WalletActivity::query()->where('wallet_id', $walletId);

        if ($after !== null) {
            $query->whereRaw('(occurred_at, id) < (?::timestamptz, ?::uuid)', [$after['value'], $after['id']]);
        }

        return array_values($query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit + 1)->get()
            ->map(fn (WalletActivity $entry): ActivityRecord => new ActivityRecord(
                id: $entry->id,
                walletId: $entry->wallet_id,
                occurredAt: $entry->occurred_at->toDateTimeImmutable(),
                actorUserId: $entry->actor_user_id,
                action: ActivityAction::from($entry->action),
                subject: ActivitySubject::from($entry->subject_type),
                subjectId: $entry->subject_id,
                role: $entry->role === null ? null : Role::from($entry->role),
            ))->all());
    }

    public function pruneBefore(DateTimeImmutable $before, int $batch): int
    {
        return DB::delete(
            'DELETE FROM wallet_activity WHERE id IN (SELECT id FROM wallet_activity WHERE occurred_at < ?::timestamptz ORDER BY occurred_at LIMIT ?)',
            [$before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), $batch],
        );
    }
}
