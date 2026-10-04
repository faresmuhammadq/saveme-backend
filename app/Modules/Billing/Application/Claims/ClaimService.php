<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Application\Jobs\SendClaimApprovedMail;
use App\Modules\Billing\Application\Jobs\SendClaimRejectedMail;
use App\Modules\Billing\Domain\Claims\ClaimPrice;
use App\Modules\Billing\Domain\Claims\ClaimReference;
use App\Modules\Billing\Domain\Claims\NewClaim;
use App\Modules\Billing\Domain\Claims\PaymentClaim;
use App\Modules\Billing\Domain\Claims\RejectReason;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\ReceiptStore;
use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Domain\Refusals\ClaimInvalid;
use App\Modules\Billing\Domain\Refusals\ClaimNotReviewable;
use App\Modules\Billing\Domain\Refusals\CodeNotRevocable;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\InvalidRejectReason;
use App\Modules\Billing\Domain\Refusals\PlanNotGrantable;
use App\Modules\Billing\Domain\Refusals\ReferenceAlreadyApproved;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\ClaimReviewedDetail;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * InstaPay claims (spec 020): submission from the website, and the operator
 * commands approve, reject and revoke a code (B12's screens and the command
 * line call these; they have no HTTP route).
 *
 * Submission performs no account lookup (FR-008): the address is normalised
 * and turned into its keyed lookup value, both pure, then sealed.
 */
final class ClaimService
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly PlanRepository $plans,
        private readonly ClaimAddresses $addresses,
        private readonly ClaimSealer $sealer,
        private readonly ReceiptStore $receipts,
        private readonly RedeemCodeRepository $codes,
        private readonly AuditLog $audit,
    ) {}

    public function submit(SubmitClaim $command): SubmittedClaim
    {
        $plan = Str::isUuid($command->planId) ? $this->plans->find($command->planId) : null;
        $faults = ClaimPrice::check($plan, $command->amountMinor, $command->currency);

        if (! ClaimReference::isValid($command->reference)) {
            $faults[] = new FieldError('/reference', ErrorCode::InvalidType);
        }

        try {
            $address = $this->addresses->normaliseAndLookup($command->address);
        } catch (InvalidArgumentException) {
            $address = null;
            $faults[] = new FieldError('/email', ErrorCode::InvalidType);
        }

        if ($faults !== [] || $plan === null || $address === null) {
            throw new ClaimInvalid($faults);
        }

        $id = (string) Str::uuid7();
        $now = now()->toDateTimeImmutable();
        $days = (int) config('billing.instapay.claim_days');
        $path = null;

        try {
            $claim = DB::transaction(function () use ($command, $address, $id, $now, $days, &$path) {
                if ($command->receipt !== null) {
                    $path = $this->receipts->put($id, $this->sealer->seal($command->receipt->bytes, $id, 'receipt'));
                }

                return $this->claims->insert(new NewClaim(
                    id: $id,
                    planId: $command->planId,
                    amountMinor: $command->amountMinor,
                    currency: $command->currency,
                    reference: ClaimReference::normalise($command->reference),
                    addressSealed: $this->sealer->seal($address['address'], $id, 'address'),
                    addressLookup: $address['lookup'],
                    locale: $command->locale,
                    receiptPath: $path,
                    receiptMediaType: $command->receipt?->mediaType,
                    receiptBytes: $command->receipt?->size(),
                    expiresAt: $now->modify("+{$days} days"),
                ));
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                $this->receipts->delete($path);
            }

            throw $e;
        }

        return new SubmittedClaim(
            $claim->id,
            $claim->status->value,
            $plan->id,
            $plan->nameIn($command->locale),
            $claim->amountMinor,
            $claim->currency,
            $claim->expiresAt,
        );
    }

    /**
     * Approves a submitted claim: issues its Redeem Code (valid 30 days, stored
     * as a hash), records one `claim_reviewed` entry and, after commit, mails
     * the code to the claim's address. The row lock makes concurrent commands
     * on one claim apply once; the unique index on approved references makes
     * the second claim with one reference fail (spec 020, FR-017 to FR-021).
     *
     * @throws ClaimNotReviewable
     * @throws PlanNotGrantable
     * @throws ReferenceAlreadyApproved
     */
    public function approve(string $operatorId, string $claimId): void
    {
        DB::transaction(function () use ($operatorId, $claimId): void {
            $claim = $this->claims->lockForUpdate($claimId) ?? throw new ClaimNotReviewable;
            $now = now()->toDateTimeImmutable();

            PaymentClaim::assertReviewable($claim, $now);

            $plan = $this->plans->find($claim->planId);

            if (ClaimPrice::of($plan) === null) {
                throw new PlanNotGrantable;
            }

            $code = RedeemCode::generate();
            $this->claims->markApproved($claim->id, $operatorId, $now);
            $issued = $this->codes->insert($claim->id, $code->hash(), $now->modify('+'.(int) config('billing.instapay.code_days').' days'));

            $this->record($operatorId, $claim->id, new ClaimReviewedDetail('approved'));
            SendClaimApprovedMail::dispatch($claim->id, $code->display(), $issued->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'))->afterCommit();
        });
    }

    /**
     * Rejects a submitted claim with a reason from the fixed list and an
     * optional internal note (at most 500 characters, never mailed or audited).
     *
     * @throws InvalidRejectReason
     * @throws ClaimNotReviewable
     */
    public function reject(string $operatorId, string $claimId, string $reason, ?string $note = null): void
    {
        $reason = RejectReason::tryFrom($reason) ?? throw new InvalidRejectReason('/reason');
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidRejectReason('/note');
        }

        DB::transaction(function () use ($operatorId, $claimId, $reason, $note): void {
            $claim = $this->claims->lockForUpdate($claimId) ?? throw new ClaimNotReviewable;
            $now = now()->toDateTimeImmutable();

            PaymentClaim::assertReviewable($claim, $now);

            $this->claims->markRejected($claim->id, $operatorId, $reason, $note, $now);
            $this->record($operatorId, $claim->id, new ClaimReviewedDetail('rejected', $reason->value));
            SendClaimRejectedMail::dispatch($claim->id)->afterCommit();
        });
    }

    /**
     * Voids an issued, unredeemed code after a mistaken approval and closes the
     * claim. The reason (1 to 500 characters) is required so the act is
     * deliberate; it is free text, so it enters neither the audit entry nor a
     * mail. No mail goes out: the operator contacts the person.
     *
     * @throws InvalidReason
     * @throws CodeNotRevocable
     */
    public function revokeCode(string $operatorId, string $claimId, string $reason): void
    {
        $length = mb_strlen(trim($reason));

        if ($length < 1 || $length > 500) {
            throw new InvalidReason;
        }

        DB::transaction(function () use ($operatorId, $claimId): void {
            $claim = $this->claims->lockForUpdate($claimId);
            $code = $claim === null ? null : $this->codes->lockForClaim($claim->id);

            if ($claim === null || $code === null) {
                throw new CodeNotRevocable;
            }

            PaymentClaim::assertCodeRevocable($code->status);

            $now = now()->toDateTimeImmutable();
            $this->codes->markRevoked($code->id, $now);
            $this->claims->close($claim->id, $now);
            $this->record($operatorId, $claim->id, new ClaimReviewedDetail('code_revoked'));
        });
    }

    private function record(string $operatorId, string $claimId, ClaimReviewedDetail $detail): void
    {
        $this->audit->record(new AuditRecord(AuditEvent::ClaimReviewed, ActorType::Operator, $operatorId, SubjectType::PaymentClaim, $claimId, $detail));
    }
}
