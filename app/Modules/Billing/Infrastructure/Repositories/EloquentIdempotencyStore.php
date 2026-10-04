<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Idempotency\IdempotencyOutcome;
use App\Modules\Billing\Domain\Ports\IdempotencyStore;
use App\Modules\Billing\Infrastructure\Models\IdempotencyRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotency records on PostgreSQL (spec 020, R-2). The claim on a key is
 * one `INSERT … ON CONFLICT DO NOTHING`, so two requests with one key never
 * both start; a request abandoned for 60 seconds is taken over by a
 * conditional update.
 */
final class EloquentIdempotencyStore implements IdempotencyStore
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    private const TAKEOVER_SECONDS = 60;

    public function begin(string $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyOutcome
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $inserted = DB::table('idempotency_records')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'scope' => $scope,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'state' => 'in_progress',
                'created_at' => $now->format(self::INSTANT),
                'expires_at' => $this->expiry($now),
            ]);

            if ($inserted === 1) {
                return IdempotencyOutcome::started();
            }

            $record = IdempotencyRecord::query()->where('scope', $scope)->where('key', $key)->first();

            if ($record === null) {
                continue;
            }

            if ($record->expires_at <= $now) {
                // A record past its 24 hours is as good as gone, whether or not the purge has run.
                IdempotencyRecord::query()->whereKey($record->id)->where('expires_at', '<=', $now->format(self::INSTANT))->toBase()->delete();

                continue;
            }

            if (! hash_equals($record->fingerprint, $fingerprint)) {
                return IdempotencyOutcome::reused();
            }

            if ($record->state === 'completed') {
                return IdempotencyOutcome::replay((int) $record->response_status, (string) $record->response_body);
            }

            $abandonedBefore = $now->modify('-'.self::TAKEOVER_SECONDS.' seconds');

            if ($record->created_at < $abandonedBefore) {
                $taken = IdempotencyRecord::query()->whereKey($record->id)->where('state', 'in_progress')
                    ->where('created_at', '<', $abandonedBefore->format(self::INSTANT))->toBase()
                    ->update(['created_at' => $now->format(self::INSTANT), 'expires_at' => $this->expiry($now)]);

                if ($taken === 1) {
                    return IdempotencyOutcome::started();
                }
            }

            return IdempotencyOutcome::inProgress();
        }

        return IdempotencyOutcome::inProgress();
    }

    public function complete(string $scope, string $key, int $status, string $body): void
    {
        IdempotencyRecord::query()->where('scope', $scope)->where('key', $key)->toBase()->update([
            'state' => 'completed',
            'response_status' => $status,
            'response_body' => $body,
        ]);
    }

    public function abandon(string $scope, string $key): void
    {
        IdempotencyRecord::query()->where('scope', $scope)->where('key', $key)->where('state', 'in_progress')->toBase()->delete();
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        return IdempotencyRecord::query()->where('expires_at', '<=', $now->format(self::INSTANT))->toBase()->delete();
    }

    private function expiry(DateTimeImmutable $now): string
    {
        $hours = (int) config('billing.idempotency_hours');

        return $now->modify("+{$hours} hours")->format(self::INSTANT);
    }
}
