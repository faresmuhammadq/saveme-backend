<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\GooglePlay\StoredPlayPurchase;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentPlayPurchaseRepository implements PlayPurchaseRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    private const PAID = "('active', 'grace', 'canceled')";

    public function find(string $id): ?StoredPlayPurchase
    {
        $row = PlayPurchase::query()->find($id);

        return $row === null ? null : self::stored($row);
    }

    public function findByLookup(string $lookup): ?StoredPlayPurchase
    {
        $row = PlayPurchase::query()->where('token_lookup', $lookup)->first();

        return $row === null ? null : self::stored($row);
    }

    public function lock(string $id): ?StoredPlayPurchase
    {
        $row = PlayPurchase::query()->whereKey($id)->lockForUpdate()->first();

        return $row === null ? null : self::stored($row);
    }

    public function insertIfAbsent(array $row): bool
    {
        $now = now()->format(self::INSTANT);

        return DB::table('play_purchases')->insertOrIgnore([
            ...$row,
            'is_test' => $row['is_test'] ? 'true' : 'false',
            'purchased_at' => $row['purchased_at']->format(self::INSTANT),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    public function linkTo(string $id, string $userId, DateTimeImmutable $at): bool
    {
        return PlayPurchase::query()->whereKey($id)->whereNull('user_id')->toBase()->update([
            'user_id' => $userId,
            'linked_at' => $at->format(self::INSTANT),
            'updated_at' => $at->format(self::INSTANT),
        ]) === 1;
    }

    public function update(string $id, array $columns): void
    {
        $values = [];

        foreach ($columns as $column => $value) {
            $values[$column] = $value instanceof DateTimeImmutable ? $value->format(self::INSTANT) : $value;
        }

        PlayPurchase::query()->whereKey($id)->toBase()->update([...$values, 'updated_at' => now()->format(self::INSTANT)]);
    }

    public function forUser(string $userId): array
    {
        return array_values(PlayPurchase::query()->where('user_id', $userId)->orderBy('id')->get()->map(fn (PlayPurchase $p): StoredPlayPurchase => self::stored($p))->all());
    }

    public function grantingPastEnd(DateTimeImmutable $now): array
    {
        return $this->ids(PlayPurchase::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'play_purchases.subscription_id')
            ->whereIn('subscriptions.status', ['active', 'grace'])
            ->whereNotNull('play_purchases.token_sealed')
            ->whereRaw('COALESCE(subscriptions.grace_until, subscriptions.current_period_end) <= ?::timestamptz', [$now->format(self::INSTANT)]));
    }

    public function unacknowledged(): array
    {
        return $this->ids(PlayPurchase::query()->whereNotNull('user_id')->whereNull('acknowledged_at')
            ->whereNotNull('token_sealed')->whereRaw('play_state IN '.self::PAID));
    }

    public function pendingSince(DateTimeImmutable $since): array
    {
        return $this->ids(PlayPurchase::query()->whereNotNull('user_id')->where('play_state', 'pending')
            ->whereNotNull('token_sealed')->where('purchased_at', '>=', $since->format(self::INSTANT)));
    }

    public function grantingSyncedBefore(DateTimeImmutable $cut): array
    {
        return $this->ids(PlayPurchase::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'play_purchases.subscription_id')
            ->whereIn('subscriptions.status', ['active', 'grace'])
            ->whereNotNull('play_purchases.token_sealed')
            ->where(fn ($q) => $q->whereNull('play_purchases.synced_at')->orWhere('play_purchases.synced_at', '<', $cut->format(self::INSTANT))));
    }

    public function unacknowledgedBefore(DateTimeImmutable $cut): int
    {
        return PlayPurchase::query()->whereNotNull('user_id')->whereNull('acknowledged_at')
            ->whereRaw('play_state IN '.self::PAID)->where('purchased_at', '<', $cut->format(self::INSTANT))->count();
    }

    public function deleteUnlinkedBefore(DateTimeImmutable $cut): int
    {
        return PlayPurchase::query()->whereNull('user_id')->where('purchased_at', '<', $cut->format(self::INSTANT))->delete();
    }

    public function eraseForAccountPurge(string $userId, DateTimeImmutable $at): int
    {
        return PlayPurchase::query()->where('user_id', $userId)->whereNull('ended_by_account_purge_at')->toBase()->update([
            'token_sealed' => null,
            'ended_by_account_purge_at' => $at->format(self::INSTANT),
            'updated_at' => $at->format(self::INSTANT),
        ]);
    }

    public function deleteEndedByAccountPurgeBefore(DateTimeImmutable $cut): int
    {
        return PlayPurchase::query()->where('ended_by_account_purge_at', '<', $cut->format(self::INSTANT))->delete();
    }

    public function linkedSince(DateTimeImmutable $since): int
    {
        return PlayPurchase::query()->where('linked_at', '>=', $since->format(self::INSTANT))->count();
    }

    /**
     * @param  Builder<PlayPurchase>  $query
     * @return list<string>
     */
    private function ids($query): array
    {
        return array_values(array_map('strval', $query->orderBy('play_purchases.id')->pluck('play_purchases.id')->all()));
    }

    private static function stored(PlayPurchase $p): StoredPlayPurchase
    {
        return new StoredPlayPurchase(
            id: $p->id,
            tokenLookup: $p->token_lookup,
            tokenSealed: $p->token_sealed,
            kind: PlayProductKind::from($p->kind),
            productId: $p->product_id,
            userId: $p->user_id,
            subscriptionId: $p->subscription_id,
            obfuscatedRef: $p->obfuscated_ref,
            replacesLookup: $p->replaces_lookup,
            playState: PlayState::from($p->play_state),
            acknowledgedAt: $p->acknowledged_at?->toDateTimeImmutable(),
            isTest: $p->is_test,
            basePlanId: $p->base_plan_id,
            purchasedAt: $p->purchased_at->toDateTimeImmutable(),
            syncedAt: $p->synced_at?->toDateTimeImmutable(),
            unreachableSince: $p->unreachable_since?->toDateTimeImmutable(),
            endedByAccountPurgeAt: $p->ended_by_account_purge_at?->toDateTimeImmutable(),
        );
    }
}
