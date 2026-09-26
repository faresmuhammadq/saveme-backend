<?php

namespace App\Modules\Admin\Infrastructure\Repositories;

use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;
use App\Modules\Admin\Infrastructure\Models\OperatorNotification;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class EloquentOperatorNotificationStore implements OperatorNotificationStore
{
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

    public function latest(int $limit): array
    {
        return array_values(OperatorNotification::query()
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
            ))
            ->all());
    }

    public function countSince(DateTimeImmutable $since): int
    {
        return OperatorNotification::query()->where('occurred_at', '>=', $since->format('Y-m-d H:i:s.uP'))->count();
    }
}
