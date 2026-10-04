<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Domain\Claims\QueuedClaim;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use App\Modules\Identity\Application\Billing\ClaimAddresses;

/**
 * What operators may read about claims (spec 020, FR-011, FR-045). The list
 * methods carry no address; the single-claim detail is the only place an
 * address is opened.
 */
final class ClaimQuery
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly RedeemCodeRepository $codes,
        private readonly ClaimSealer $sealer,
        private readonly ClaimAddresses $addresses,
    ) {}

    /**
     * Submitted claims first, oldest first, then the rest; no address.
     *
     * @return list<ClaimSummary>
     */
    public function queue(?string $afterId = null, int $limit = 50): array
    {
        return array_map(
            fn (QueuedClaim $queued): ClaimSummary => ClaimSummary::of($queued->claim, $queued->duplicateCount),
            $this->claims->queue($afterId, $limit),
        );
    }

    /**
     * The other claims carrying this claim's normalised reference.
     *
     * @return list<ClaimSummary>
     */
    public function duplicatesOf(string $claimId): array
    {
        $claim = $this->claims->find($claimId);

        if ($claim === null) {
            return [];
        }

        return array_map(
            fn ($other): ClaimSummary => ClaimSummary::of($other),
            $this->claims->duplicatesOf($claim->reference, $claim->id),
        );
    }

    /**
     * One claim with its address opened and whether an account holds it now.
     * The caller MUST record an `admin_action` `claim_address_read` (Admin's
     * AdminAuditService::claimReadByOperator) for every call.
     */
    public function detail(string $claimId): ?ClaimDetail
    {
        $claim = $this->claims->find($claimId);

        if ($claim === null) {
            return null;
        }

        $code = $this->codes->forClaim($claim->id);
        $duplicates = $this->duplicatesOf($claim->id);

        return new ClaimDetail(
            summary: ClaimSummary::of($claim, count($duplicates)),
            locale: $claim->locale,
            address: $claim->addressSealed === null ? null : $this->sealer->open($claim->addressSealed, $claim->id, 'address'),
            accountExists: $claim->addressLookup !== null && $this->addresses->accountFor($claim->addressLookup) !== null,
            hasReceipt: $claim->receiptPath !== null,
            receiptMediaType: $claim->receiptMediaType,
            receiptBytes: $claim->receiptBytes,
            reviewedBy: $claim->reviewedBy,
            reviewedAt: $claim->reviewedAt,
            rejectReason: $claim->rejectReason?->value,
            reviewNote: $claim->reviewNote,
            closedAt: $claim->closedAt,
            erasedAt: $claim->erasedAt,
            codeStatus: $code?->status->value,
            codeExpiresAt: $code?->expiresAt,
            duplicates: $duplicates,
        );
    }
}
