<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

    public function lockForUser(string $userId): array
    {
        return $this->records(Subscription::query()->where('user_id', $userId)->lockForUpdate());
    }

    public function findByProviderRef(string $provider, string $providerRef): ?SubscriptionRecord
    {
        $subscription = Subscription::query()->where('provider', $provider)->where('provider_ref', $providerRef)->first();

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

    public function stampEndedByAccountPurge(string $userId, DateTimeImmutable $at): int
    {
        return Subscription::query()->where('user_id', $userId)->whereNull('ended_by_account_purge_at')->toBase()->update([
            'ended_by_account_purge_at' => $at->format(self::INSTANT),
            'updated_at' => $at->format(self::INSTANT),
        ]);
    }

    public function deleteEndedByAccountPurgeBefore(DateTimeImmutable $cut): int
    {
        return Subscription::query()->where('ended_by_account_purge_at', '<', $cut->format(self::INSTANT))->delete();
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

    public function dueForReminder(DateTimeImmutable $now, int $days): array
    {
        return $this->records(Subscription::query()->where('provider', 'instapay')->where('status', 'active')
            ->whereNull('renewal_reminded_at')
            ->where('current_period_end', '>', $now->format(self::INSTANT))
            ->where('current_period_end', '<=', $now->modify("+{$days} days")->format(self::INSTANT)));
    }

    public function markReminded(string $id, DateTimeImmutable $at): bool
    {
        return Subscription::query()->whereKey($id)->whereNull('renewal_reminded_at')->toBase()->update([
            'renewal_reminded_at' => $at->format(self::INSTANT),
            'updated_at' => $at->format(self::INSTANT),
        ]) === 1;
    }

    public function governedCountsByPlan(DateTimeImmutable $now): array
    {
        $at = $now->format(self::INSTANT);

        $rows = DB::select(<<<'SQL'
            SELECT plan_id, COUNT(*) AS people FROM (
                SELECT DISTINCT ON (user_id) user_id, plan_id
                FROM subscriptions
                WHERE status IN ('active', 'grace')
                  AND (COALESCE(grace_until, current_period_end) IS NULL OR COALESCE(grace_until, current_period_end) > ?::timestamptz)
                ORDER BY user_id, (current_period_end IS NULL) DESC, current_period_end DESC, created_at DESC, id DESC
            ) governing
            GROUP BY plan_id
            SQL, [$at]);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->plan_id] = (int) $row->people;
        }

        return $counts;
    }

    public function figures(DateTimeImmutable $now): array
    {
        $at = $now->format(self::INSTANT);

        $rows = DB::select(<<<'SQL'
            SELECT s.provider, COUNT(DISTINCT s.user_id) AS people
            FROM subscriptions s
            JOIN plans p ON p.id = s.plan_id
            WHERE s.status IN ('active', 'grace')
              AND (COALESCE(s.grace_until, s.current_period_end) IS NULL OR COALESCE(s.grace_until, s.current_period_end) > ?::timestamptz)
              AND NOT p.is_default
            GROUP BY s.provider
            SQL, [$at]);

        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[(string) $row->provider] = (int) $row->people;
        }

        return [
            'google_play' => $byChannel['google_play'] ?? 0,
            'instapay' => $byChannel['instapay'] ?? 0,
            'manual' => $byChannel['manual'] ?? 0,
            'in_grace' => Subscription::query()->where('status', 'grace')
                ->where(fn ($query) => $query->whereNull('grace_until')->orWhere('grace_until', '>', $at))->count(),
        ];
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
