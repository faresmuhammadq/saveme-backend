<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\GooglePlay\StoredPlayNotification;
use App\Modules\Billing\Domain\Ports\PlayNotificationRepository;
use App\Modules\Billing\Infrastructure\Models\PlayNotification;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentPlayNotificationRepository implements PlayNotificationRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function insertIfAbsent(array $row): bool
    {
        return DB::table('play_notifications')->insertOrIgnore([
            ...$row,
            'event_at' => $row['event_at']?->format(self::INSTANT),
            'published_at' => $row['published_at']?->format(self::INSTANT),
            'received_at' => $row['received_at']->format(self::INSTANT),
            'attempts' => 0,
            'updated_at' => $row['received_at']->format(self::INSTANT),
        ]) === 1;
    }

    public function find(string $id): ?StoredPlayNotification
    {
        $row = PlayNotification::query()->find($id);

        return $row === null ? null : self::stored($row);
    }

    public function lock(string $id): ?StoredPlayNotification
    {
        $row = PlayNotification::query()->whereKey($id)->lockForUpdate()->first();

        return $row === null ? null : self::stored($row);
    }

    public function incrementAttempts(string $id): void
    {
        PlayNotification::query()->whereKey($id)->toBase()->increment('attempts', 1, ['updated_at' => now()->format(self::INSTANT)]);
    }

    public function markProcessed(string $id): void
    {
        $this->terminal($id, 'processed', null);
    }

    public function markIgnored(string $id, string $reason): void
    {
        $this->terminal($id, 'ignored', $reason);
    }

    public function markFailed(string $id): void
    {
        $this->terminal($id, 'failed', null);
    }

    public function deleteReceivedBefore(DateTimeImmutable $cut): int
    {
        return PlayNotification::query()->where('received_at', '<', $cut->format(self::INSTANT))->delete();
    }

    public function countsSince(DateTimeImmutable $since): array
    {
        /** @var array<string, int> $counts */
        $counts = PlayNotification::query()->where('received_at', '>=', $since->format(self::INSTANT))
            ->selectRaw('state, COUNT(*) AS total')->groupBy('state')->pluck('total', 'state')
            ->map(fn (mixed $total): int => (int) $total)->all();

        return $counts;
    }

    /**
     * A terminal row never holds the token again (spec 024, FR-038).
     */
    private function terminal(string $id, string $state, ?string $reason): void
    {
        PlayNotification::query()->whereKey($id)->toBase()->update([
            'state' => $state,
            'ignored_reason' => $reason,
            'token_sealed' => null,
            'updated_at' => now()->format(self::INSTANT),
        ]);
    }

    private static function stored(PlayNotification $n): StoredPlayNotification
    {
        return new StoredPlayNotification(
            id: $n->id,
            messageId: $n->message_id,
            kind: $n->kind,
            notificationType: $n->notification_type,
            productId: $n->product_id,
            tokenLookup: $n->token_lookup,
            tokenSealed: $n->token_sealed,
            eventAt: $n->event_at?->toDateTimeImmutable(),
            state: $n->state,
            ignoredReason: $n->ignored_reason,
            attempts: $n->attempts,
        );
    }
}
