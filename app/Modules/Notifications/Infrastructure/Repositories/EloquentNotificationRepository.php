<?php

namespace App\Modules\Notifications\Infrastructure\Repositories;

use App\Modules\Notifications\Domain\Delivery\NotificationRecord;
use App\Modules\Notifications\Domain\Delivery\NotificationState;
use App\Modules\Notifications\Domain\Delivery\SkipReason;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use App\Modules\Notifications\Infrastructure\Models\PushNotification;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentNotificationRepository implements NotificationRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function createIfAbsent(NotificationDraft $draft, ?SkipReason $skipped = null): ?NotificationRecord
    {
        $id = (string) Str::uuid7();
        $at = now()->format(self::INSTANT);

        $inserted = DB::selectOne(
            'INSERT INTO push_notifications (id, user_id, type, category, dedupe_key, data, collapse_key, state, skip_reason, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id, dedupe_key) DO NOTHING
             RETURNING id',
            [
                $id,
                $draft->userId,
                $draft->type->value,
                $draft->type->category()->value,
                $draft->dedupeKey,
                json_encode($draft->data === [] ? (object) [] : $draft->data, JSON_THROW_ON_ERROR),
                $draft->type->collapseKey($draft->data),
                $skipped === null ? NotificationState::Pending->value : NotificationState::Skipped->value,
                $skipped?->value,
                $at,
                $at,
            ],
        );

        return $inserted === null ? null : $this->find($id);
    }

    public function find(string $id): ?NotificationRecord
    {
        $row = PushNotification::query()->find($id);

        return $row === null ? null : self::record($row);
    }

    public function lockForDelivery(string $id): ?NotificationRecord
    {
        $row = PushNotification::query()->whereKey($id)->lockForUpdate()->first();

        return $row === null ? null : self::record($row);
    }

    public function markHeld(string $id, DateTimeImmutable $notBefore): void
    {
        $this->change($id, [
            'state' => NotificationState::Held->value,
            'not_before' => $notBefore->format(self::INSTANT),
            'sending_since' => null,
            'skip_reason' => null,
        ]);
    }

    public function markSending(string $id, DateTimeImmutable $since): void
    {
        $this->change($id, ['state' => NotificationState::Sending->value, 'not_before' => null, 'sending_since' => $since->format(self::INSTANT), 'skip_reason' => null]);
    }

    public function markSent(string $id, DateTimeImmutable $at): void
    {
        $this->change($id, ['state' => NotificationState::Sent->value, 'not_before' => null, 'sending_since' => null, 'sent_at' => $at->format(self::INSTANT), 'skip_reason' => null]);
    }

    public function markSkipped(string $id, SkipReason $reason): void
    {
        $this->change($id, ['state' => NotificationState::Skipped->value, 'skip_reason' => $reason->value, 'not_before' => null, 'sending_since' => null]);
    }

    public function markFailed(string $id): void
    {
        $this->change($id, ['state' => NotificationState::Failed->value, 'not_before' => null, 'sending_since' => null, 'skip_reason' => null]);
    }

    public function dueHeld(DateTimeImmutable $now, int $limit): array
    {
        return $this->records(PushNotification::query()
            ->where('state', NotificationState::Held->value)
            ->where('not_before', '<=', $now->format(self::INSTANT))
            ->orderBy('created_at')->orderBy('id')->limit($limit));
    }

    public function allHeld(int $limit): array
    {
        return $this->records(PushNotification::query()
            ->where('state', NotificationState::Held->value)
            ->orderBy('created_at')->orderBy('id')->limit($limit));
    }

    public function staleSending(DateTimeImmutable $before, int $limit): array
    {
        return $this->records(PushNotification::query()
            ->where('state', NotificationState::Sending->value)
            ->where('sending_since', '<', $before->format(self::INSTANT))
            ->orderBy('sending_since')->limit($limit));
    }

    public function sentCountSince(string $userId, DateTimeImmutable $since, ?string $exceptId = null): int
    {
        $cut = $since->format(self::INSTANT);

        return PushNotification::query()
            ->where('user_id', $userId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(function ($query) use ($cut): void {
                $query->where('sent_at', '>=', $cut)
                    ->orWhere(fn ($sending) => $sending->where('state', NotificationState::Sending->value)->where('sending_since', '>=', $cut));
            })
            ->count();
    }

    public function existingKeys(string $userId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return array_values(PushNotification::query()->where('user_id', $userId)->whereIn('dedupe_key', $keys)->pluck('dedupe_key')->map(fn (mixed $key): string => (string) $key)->all());
    }

    public function deleteCreatedBefore(DateTimeImmutable $cut): int
    {
        return PushNotification::query()->where('created_at', '<', $cut->format(self::INSTANT))->delete();
    }

    public function deleteAllFor(string $userId): int
    {
        return PushNotification::query()->where('user_id', $userId)->delete();
    }

    public function figuresSince(DateTimeImmutable $since): array
    {
        $rows = PushNotification::query()
            ->where('created_at', '>=', $since->format(self::INSTANT))
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $figures = ['created' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $state => $total) {
            $figures['created'] += (int) $total;

            if (isset($figures[$state])) {
                $figures[$state] = (int) $total;
            }
        }

        return $figures;
    }

    /**
     * @param  array<string, scalar|null>  $columns
     */
    private function change(string $id, array $columns): void
    {
        PushNotification::query()->whereKey($id)->toBase()->update([...$columns, 'updated_at' => now()->format(self::INSTANT)]);
    }

    /**
     * @param  Builder<PushNotification>  $query
     * @return list<NotificationRecord>
     */
    private function records($query): array
    {
        return array_values($query->get()->map(fn (PushNotification $row): NotificationRecord => self::record($row))->all());
    }

    private static function record(PushNotification $row): NotificationRecord
    {
        /** @var array<string, string> $data */
        $data = $row->data;

        return new NotificationRecord(
            $row->id,
            $row->user_id,
            MessageType::from($row->type),
            $row->dedupe_key,
            $data,
            $row->collapse_key,
            NotificationState::from($row->state),
            $row->skip_reason === null ? null : SkipReason::from($row->skip_reason),
            $row->not_before?->toDateTimeImmutable(),
            $row->sending_since?->toDateTimeImmutable(),
            $row->sent_at?->toDateTimeImmutable(),
            $row->created_at->toDateTimeImmutable(),
        );
    }
}
