<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentSubscriptionRepository implements SubscriptionRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function forUser(string $userId): array
    {
        return $this->records(Subscription::query()->where('user_id', $userId));
    }

    public function find(string $id): ?SubscriptionRecord
    {
        $subscription = Subscription::query()->find($id);

        return $subscription === null ? null : self::record($subscription);
    }

    public function create(array $row): SubscriptionRecord
    {
        $subscription = Subscription::query()->create([...$row, 'source_meta' => []]);

        return self::record($subscription->refresh());
    }

    public function transition(string $id, SubscriptionStatus $from, SubscriptionStatus $to, array $changes = []): bool
    {
        return Subscription::query()->whereKey($id)->where('status', $from->value)->toBase()->update([
            'status' => $to->value,
            ...self::instants($changes),
            'updated_at' => now()->format(self::INSTANT),
        ]) === 1;
    }

    public function updateDates(string $id, array $changes): void
    {
        Subscription::query()->whereKey($id)->toBase()->update([
            ...self::instants($changes),
            'updated_at' => now()->format(self::INSTANT),
        ]);
    }

    public function dueForGrace(DateTimeImmutable $now): array
    {
        return $this->records(Subscription::query()->where('status', 'active')
            ->whereNotNull('current_period_end')->where('current_period_end', '<=', $now->format(self::INSTANT)));
    }

    public function dueForExpiry(DateTimeImmutable $now): array
    {
        return $this->records(Subscription::query()->where('status', 'grace')
            ->whereNotNull('grace_until')->where('grace_until', '<=', $now->format(self::INSTANT)));
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return list<SubscriptionRecord>
     */
    private function records($query): array
    {
        return array_values($query->orderBy('id')->get()->map(fn (Subscription $s): SubscriptionRecord => self::record($s))->all());
    }

    /**
     * @param  array<string, DateTimeImmutable|null>  $changes
     * @return array<string, string|null>
     */
    private static function instants(array $changes): array
    {
        return array_map(fn (?DateTimeImmutable $at): ?string => $at?->format(self::INSTANT), $changes);
    }

    private static function record(Subscription $s): SubscriptionRecord
    {
        return new SubscriptionRecord(
            id: $s->id,
            userId: $s->user_id,
            planId: $s->plan_id,
            provider: $s->provider,
            providerRef: $s->provider_ref,
            status: SubscriptionStatus::from($s->status),
            currentPeriodStart: $s->current_period_start->toDateTimeImmutable(),
            currentPeriodEnd: $s->current_period_end?->toDateTimeImmutable(),
            graceUntil: $s->grace_until?->toDateTimeImmutable(),
            autoRenew: $s->auto_renew,
            createdAt: $s->created_at->toDateTimeImmutable(),
        );
    }
}
