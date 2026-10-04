<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCodeRecord;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCodeStatus;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentRedeemCodeRepository implements RedeemCodeRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function insert(string $claimId, string $codeHash, DateTimeImmutable $expiresAt): RedeemCodeRecord
    {
        $now = now()->format(self::INSTANT);
        $id = (string) Str::uuid7();

        RedeemCode::query()->toBase()->insert([
            'id' => $id,
            'claim_id' => $claimId,
            'code_hash' => $codeHash,
            'status' => RedeemCodeStatus::Issued->value,
            'expires_at' => $expiresAt->format(self::INSTANT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::record(RedeemCode::query()->findOrFail($id));
    }

    public function lockByHash(string $codeHash): ?RedeemCodeRecord
    {
        $code = RedeemCode::query()->where('code_hash', $codeHash)->lockForUpdate()->first();

        return $code === null ? null : self::record($code);
    }

    public function lockForClaim(string $claimId): ?RedeemCodeRecord
    {
        $code = RedeemCode::query()->where('claim_id', $claimId)->lockForUpdate()->first();

        return $code === null ? null : self::record($code);
    }

    public function forClaim(string $claimId): ?RedeemCodeRecord
    {
        $code = RedeemCode::query()->where('claim_id', $claimId)->first();

        return $code === null ? null : self::record($code);
    }

    public function markRedeemed(string $id, string $userId, string $subscriptionId, DateTimeImmutable $now): void
    {
        RedeemCode::query()->whereKey($id)->where('status', RedeemCodeStatus::Issued->value)->toBase()->update([
            'status' => RedeemCodeStatus::Redeemed->value,
            'redeemed_by' => $userId,
            'redeemed_at' => $now->format(self::INSTANT),
            'subscription_id' => $subscriptionId,
            'updated_at' => $now->format(self::INSTANT),
        ]);
    }

    public function markRevoked(string $id, DateTimeImmutable $now): void
    {
        RedeemCode::query()->whereKey($id)->where('status', RedeemCodeStatus::Issued->value)->toBase()->update([
            'status' => RedeemCodeStatus::Revoked->value,
            'revoked_at' => $now->format(self::INSTANT),
            'updated_at' => $now->format(self::INSTANT),
        ]);
    }

    public function expireDue(DateTimeImmutable $now): array
    {
        $at = $now->format(self::INSTANT);

        $rows = DB::select(
            "UPDATE redeem_codes SET status = 'expired', updated_at = ?
             WHERE status = 'issued' AND expires_at <= ?
             RETURNING claim_id",
            [$at, $at],
        );

        return array_values(array_map(fn (object $row): string => (string) ((array) $row)['claim_id'], $rows));
    }

    private static function record(RedeemCode $c): RedeemCodeRecord
    {
        return new RedeemCodeRecord(
            id: $c->id,
            claimId: $c->claim_id,
            codeHash: $c->code_hash,
            status: RedeemCodeStatus::from($c->status),
            expiresAt: $c->expires_at->toDateTimeImmutable(),
            redeemedBy: $c->redeemed_by,
            redeemedAt: $c->redeemed_at?->toDateTimeImmutable(),
            subscriptionId: $c->subscription_id,
            revokedAt: $c->revoked_at?->toDateTimeImmutable(),
        );
    }
}
