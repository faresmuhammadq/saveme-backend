<?php

namespace App\Modules\Admin\Infrastructure\Repositories;

use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;
use App\Modules\Admin\Infrastructure\Models\OperatorNotification;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentOperatorNotificationStore implements OperatorNotificationStore
{
    /** Instants keep microseconds. */
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function recordOnce(string $kind, string $subjectUserId, DateTimeImmutable $occurredAt, array $payload, string $sourceEventId): bool
    {
        return OperatorNotification::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'kind' => $kind,
            'subject_user_id' => $subjectUserId,
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'),
            'payload' => json_encode((object) $payload),
            'source_event_id' => $sourceEventId,
            'created_at' => now()->format('Y-m-d H:i:s.uP'),
        ]) > 0;
    }

    public function latestFor(string $operatorId, int $limit): array
    {
        return array_values(OperatorNotification::query()
            ->select('operator_notifications.*')
            ->selectRaw('EXISTS (SELECT 1 FROM operator_notification_reads r WHERE r.notification_id = operator_notifications.id AND r.operator_id = ?) AS is_read', [$operatorId])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (OperatorNotification $notification): OperatorNotificationView => new OperatorNotificationView(
                id: $notification->id,
                kind: $notification->kind,
                subjectUserId: $notification->subject_user_id,
                occurredAt: $notification->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                titleKey: "admin::notifications.kinds.{$notification->kind}.title",
                bodyKey: "admin::notifications.kinds.{$notification->kind}.body",
                params: $notification->payload,
                read: (bool) $notification->getAttribute('is_read'),
            ))
            ->all());
    }

    public function unreadCountFor(string $operatorId, DateTimeImmutable $since): int
    {
        return OperatorNotification::query()
            ->where('occurred_at', '>=', $since->format(self::INSTANT))
            ->whereNotExists(fn ($read) => $read->selectRaw('1')->from('operator_notification_reads as r')
                ->whereColumn('r.notification_id', 'operator_notifications.id')->where('r.operator_id', $operatorId))
            ->count();
    }

    public function markRead(string $operatorId, string $notificationId, DateTimeImmutable $at): void
    {
        DB::statement(
            'INSERT INTO operator_notification_reads (operator_id, notification_id, read_at)
             SELECT ?, id, ?::timestamptz FROM operator_notifications WHERE id = ?
             ON CONFLICT DO NOTHING',
            [$operatorId, $at->format(self::INSTANT), $notificationId],
        );
    }

    public function markAllRead(string $operatorId, DateTimeImmutable $since, DateTimeImmutable $at): void
    {
        DB::statement(
            'INSERT INTO operator_notification_reads (operator_id, notification_id, read_at)
             SELECT ?, id, ?::timestamptz FROM operator_notifications WHERE occurred_at >= ?::timestamptz
             ON CONFLICT DO NOTHING',
            [$operatorId, $at->format(self::INSTANT), $since->format(self::INSTANT)],
        );
    }
}
