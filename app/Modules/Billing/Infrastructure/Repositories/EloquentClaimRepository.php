<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Claims\ClaimRecord;
use App\Modules\Billing\Domain\Claims\ClaimStatus;
use App\Modules\Billing\Domain\Claims\NewClaim;
use App\Modules\Billing\Domain\Claims\QueuedClaim;
use App\Modules\Billing\Domain\Claims\RejectReason;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Refusals\ReferenceAlreadyApproved;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentClaimRepository implements ClaimRepository
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function insert(NewClaim $claim): ClaimRecord
    {
        $now = now()->format(self::INSTANT);

        PaymentClaim::query()->toBase()->insert([
            'id' => $claim->id,
            'plan_id' => $claim->planId,
            'amount_minor' => $claim->amountMinor,
            'currency' => $claim->currency,
            'reference' => $claim->reference,
            'address_sealed' => $claim->addressSealed,
            'address_lookup' => $claim->addressLookup,
            'locale' => $claim->locale,
            'receipt_path' => $claim->receiptPath,
            'receipt_media_type' => $claim->receiptMediaType?->value,
            'receipt_bytes' => $claim->receiptBytes,
            'status' => ClaimStatus::Submitted->value,
            'expires_at' => $claim->expiresAt->format(self::INSTANT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($claim->id) ?? throw new \RuntimeException('The claim was not stored.');
    }

    public function find(string $id): ?ClaimRecord
    {
        $claim = PaymentClaim::query()->find($id);

        return $claim === null ? null : self::record($claim);
    }

    public function lockForUpdate(string $id): ?ClaimRecord
    {
        $claim = PaymentClaim::query()->whereKey($id)->lockForUpdate()->first();

        return $claim === null ? null : self::record($claim);
    }

    public function duplicatesOf(string $reference, ?string $exceptId = null): array
    {
        $query = PaymentClaim::query()->where('reference', $reference);

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return array_values($query->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (PaymentClaim $claim): ClaimRecord => self::record($claim))->all());
    }

    public function markApproved(string $id, string $operatorId, DateTimeImmutable $now): void
    {
        try {
            // A savepoint inside the caller's transaction: the unique violation
            // must not poison it (PostgreSQL aborts a transaction on any error).
            DB::transaction(fn () => PaymentClaim::query()->whereKey($id)->where('status', ClaimStatus::Submitted->value)->toBase()->update([
                'status' => ClaimStatus::Approved->value,
                'reviewed_by' => $operatorId,
                'reviewed_at' => $now->format(self::INSTANT),
                'updated_at' => $now->format(self::INSTANT),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new ReferenceAlreadyApproved;
        }
    }

    public function markRejected(string $id, string $operatorId, RejectReason $reason, ?string $note, DateTimeImmutable $now): void
    {
        PaymentClaim::query()->whereKey($id)->where('status', ClaimStatus::Submitted->value)->toBase()->update([
            'status' => ClaimStatus::Rejected->value,
            'reviewed_by' => $operatorId,
            'reviewed_at' => $now->format(self::INSTANT),
            'reject_reason' => $reason->value,
            'review_note' => $note,
            'closed_at' => $now->format(self::INSTANT),
            'updated_at' => $now->format(self::INSTANT),
        ]);
    }

    public function close(string $id, DateTimeImmutable $now): void
    {
        PaymentClaim::query()->whereKey($id)->whereNull('closed_at')->toBase()->update([
            'closed_at' => $now->format(self::INSTANT),
            'updated_at' => $now->format(self::INSTANT),
        ]);
    }

    public function queue(?string $afterId, int $limit): array
    {
        $query = PaymentClaim::query()
            ->orderByRaw("(status <> 'submitted')")
            ->orderBy('created_at')
            ->orderBy('id');

        if ($afterId !== null && ($cursor = PaymentClaim::query()->find($afterId)) !== null) {
            $query->whereRaw("((status <> 'submitted'), created_at, id) > (?, ?, ?)", [
                $cursor->status !== ClaimStatus::Submitted->value,
                $cursor->created_at->format(self::INSTANT),
                $cursor->id,
            ]);
        }

        $claims = $query->limit($limit)->get();
        $counts = PaymentClaim::query()->whereIn('reference', $claims->pluck('reference')->unique()->all())
            ->groupBy('reference')->selectRaw('reference, count(*) as total')->pluck('total', 'reference');

        return array_values($claims->map(fn (PaymentClaim $claim): QueuedClaim => new QueuedClaim(
            self::record($claim),
            max(0, (int) ($counts[$claim->reference] ?? 1) - 1),
        ))->all());
    }

    public function expireDue(DateTimeImmutable $now, int $limit): array
    {
        $at = $now->format(self::INSTANT);

        $rows = DB::select(
            "UPDATE payment_claims SET status = 'expired', closed_at = ?, updated_at = ?
             WHERE id IN (SELECT id FROM payment_claims WHERE status = 'submitted' AND expires_at <= ? ORDER BY expires_at, id LIMIT ? FOR UPDATE SKIP LOCKED)
               AND status = 'submitted'
             RETURNING id",
            [$at, $at, $at, $limit],
        );

        return array_values(array_map(fn (object $row): string => (string) ((array) $row)['id'], $rows));
    }

    public function erasableClosedBefore(DateTimeImmutable $cutoff, int $limit): array
    {
        return array_values(PaymentClaim::query()->whereNotNull('closed_at')->whereNull('erased_at')
            ->where('closed_at', '<=', $cutoff->format(self::INSTANT))->orderBy('closed_at')->orderBy('id')->limit($limit)->get()
            ->map(fn (PaymentClaim $claim): ClaimRecord => self::record($claim))->all());
    }

    public function markErased(string $id, DateTimeImmutable $now): void
    {
        PaymentClaim::query()->whereKey($id)->whereNull('erased_at')->toBase()->update([
            'address_sealed' => null,
            'address_lookup' => null,
            'receipt_path' => null,
            'receipt_media_type' => null,
            'receipt_bytes' => null,
            'erased_at' => $now->format(self::INSTANT),
            'updated_at' => $now->format(self::INSTANT),
        ]);
    }

    public function deleteClosedBefore(DateTimeImmutable $cutoff): int
    {
        return PaymentClaim::query()->whereNotNull('closed_at')
            ->where('closed_at', '<=', $cutoff->format(self::INSTANT))->toBase()->delete();
    }

    private static function record(PaymentClaim $c): ClaimRecord
    {
        return new ClaimRecord(
            id: $c->id,
            planId: $c->plan_id,
            amountMinor: $c->amount_minor,
            currency: $c->currency,
            reference: $c->reference,
            addressSealed: $c->address_sealed,
            addressLookup: $c->address_lookup,
            locale: $c->locale,
            receiptPath: $c->receipt_path,
            receiptMediaType: $c->receipt_media_type,
            receiptBytes: $c->receipt_bytes,
            status: ClaimStatus::from($c->status),
            expiresAt: $c->expires_at->toDateTimeImmutable(),
            reviewedBy: $c->reviewed_by,
            reviewedAt: $c->reviewed_at?->toDateTimeImmutable(),
            rejectReason: $c->reject_reason === null ? null : RejectReason::from($c->reject_reason),
            reviewNote: $c->review_note,
            closedAt: $c->closed_at?->toDateTimeImmutable(),
            erasedAt: $c->erased_at?->toDateTimeImmutable(),
            createdAt: $c->created_at->toDateTimeImmutable(),
        );
    }
}
