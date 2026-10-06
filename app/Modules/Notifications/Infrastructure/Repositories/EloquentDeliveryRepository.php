<?php

namespace App\Modules\Notifications\Infrastructure\Repositories;

use App\Modules\Notifications\Domain\Delivery\DeliveryRecord;
use App\Modules\Notifications\Domain\Delivery\DeliveryState;
use App\Modules\Notifications\Domain\Ports\DeliveryRepository;
use App\Modules\Notifications\Infrastructure\Models\PushDelivery;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentDeliveryRepository implements DeliveryRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function ensureFor(string $notificationId, array $registrationIds): array
    {
        $at = now()->format(self::INSTANT);

        foreach ($registrationIds as $registrationId) {
            DB::statement(
                'INSERT INTO push_deliveries (id, notification_id, registration_id, state, attempts, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)
                 ON CONFLICT (notification_id, registration_id) DO NOTHING',
                [(string) Str::uuid7(), $notificationId, $registrationId, DeliveryState::Pending->value, $at, $at],
            );
        }

        return $this->forNotification($notificationId);
    }

    public function forNotification(string $notificationId): array
    {
        return array_values(PushDelivery::query()->where('notification_id', $notificationId)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (PushDelivery $row): DeliveryRecord => new DeliveryRecord(
                $row->id,
                $row->notification_id,
                $row->registration_id,
                DeliveryState::from($row->state),
                $row->attempts,
                $row->last_error,
            ))
            ->all());
    }

    public function record(string $deliveryId, DeliveryState $state, ?string $error): void
    {
        PushDelivery::query()->whereKey($deliveryId)->toBase()->update([
            'state' => $state->value,
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => $error === null ? null : substr($error, 0, 64),
            'updated_at' => now()->format(self::INSTANT),
        ]);
    }

    public function prunedSince(DateTimeImmutable $since): int
    {
        return PushDelivery::query()
            ->where('state', DeliveryState::Pruned->value)
            ->where('updated_at', '>=', $since->format(self::INSTANT))
            ->count();
    }
}
