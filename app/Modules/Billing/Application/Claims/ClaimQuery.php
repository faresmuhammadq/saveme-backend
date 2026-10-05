<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Domain\Claims\ClaimPrice;
use App\Modules\Billing\Domain\Claims\ClaimRecord;
use App\Modules\Billing\Domain\Claims\ClaimReference;
use App\Modules\Billing\Domain\Claims\QueuedClaim;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use DateTimeImmutable;
use InvalidArgumentException;

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
        private readonly PlanRepository $plans,
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
     * One page of the queue by status, newest or oldest first as the status
     * demands (spec 021, FR-015). No address in any item. `$search` is
     * `['reference' => raw]` (normalised here), `['lookup' => value]` (from
     * `lookupFor`) or null.
     *
     * @param  array{reference?: string, lookup?: string}|null  $search
     */
    public function page(ClaimFilter $status, ?array $search, ?string $after, int $limit = 25): ClaimPage
    {
        $reference = isset($search['reference']) ? ClaimReference::normalise($search['reference']) : null;
        $page = $this->claims->page($status->status(), $reference, $search['lookup'] ?? null, $after, $limit, now()->toDateTimeImmutable());
        $names = [];

        return new ClaimPage(
            array_map(function (QueuedClaim $queued) use (&$names): ClaimSummary {
                $names[$queued->claim->planId] ??= $this->planName($queued->claim->planId);

                return ClaimSummary::of($queued->claim, $queued->duplicateCount, $names[$queued->claim->planId]);
            }, $page['items']),
            $page['next'],
        );
    }

    /**
     * Submitted claims that can still be reviewed, and when the oldest was made.
     *
     * @return array{count: int, oldest: ?DateTimeImmutable}
     */
    public function awaitingSummary(): array
    {
        return $this->claims->awaitingSummary(now()->toDateTimeImmutable());
    }

    /**
     * The review facts and code state with no address: what a review page
     * refreshes after an action without opening the address again.
     */
    public function reviewState(string $claimId): ?ClaimReviewState
    {
        $claim = $this->claims->find($claimId);

        if ($claim === null) {
            return null;
        }

        $code = $this->codes->forClaim($claim->id);

        return new ClaimReviewState(
            status: $claim->status->value,
            expiresAt: $claim->expiresAt,
            reviewedBy: $claim->reviewedBy,
            reviewedAt: $claim->reviewedAt,
            rejectReason: $claim->rejectReason?->value,
            reviewNote: $claim->reviewNote,
            closedAt: $claim->closedAt,
            erasedAt: $claim->erasedAt,
            codeStatus: $code?->status->value,
            codeExpiresAt: $code?->expiresAt,
        );
    }

    /**
     * The keyed lookup value of a typed address, or null when it is not an
     * address. Pure: no account or claim is read.
     */
    public function lookupFor(string $address): ?string
    {
        try {
            return $this->addresses->normaliseAndLookup($address)['lookup'];
        } catch (InvalidArgumentException) {
            return null;
        }
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
            fn ($other): ClaimSummary => ClaimSummary::of($other, 0, $this->planName($other->planId)),
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
            summary: ClaimSummary::of($claim, count($duplicates), $this->planName($claim->planId)),
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
            matchesCurrentPrice: $this->matchesCurrentPrice($claim),
        );
    }

    private function matchesCurrentPrice(ClaimRecord $claim): bool
    {
        $price = ClaimPrice::of($this->plans->find($claim->planId));

        return $price !== null && $price['amount_minor'] === $claim->amountMinor && $price['currency'] === $claim->currency;
    }

    /**
     * @return array{en: string, ar: string}|null
     */
    private function planName(string $planId): ?array
    {
        $name = $this->plans->find($planId)?->name;

        return $name === null ? null : ['en' => $name['en'], 'ar' => $name['ar']];
    }
}
