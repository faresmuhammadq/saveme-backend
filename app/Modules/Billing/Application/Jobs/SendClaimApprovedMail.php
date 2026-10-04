<?php

namespace App\Modules\Billing\Application\Jobs;

use App\Modules\Billing\Application\Mail\ClaimApprovedMail;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mails the redeem code to the claim's address, in the claim's language
 * (spec 020, FR-017, R-13). The payload carries the plaintext code, so the job
 * is encrypted at rest in the queue. The address is opened only here, at send
 * time, so a claim whose address was erased sends nothing. Neither the code
 * nor the address is logged; a failure logs a warning without any identifier.
 */
final class SendClaimApprovedMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly string $claimId,
        public readonly string $code,
        public readonly string $validUntil,
    ) {}

    public function handle(ClaimRepository $claims, ClaimSealer $sealer, PlanRepository $plans): void
    {
        $claim = $claims->find($this->claimId);

        if ($claim === null || $claim->addressSealed === null) {
            return;
        }

        Mail::to($sealer->open($claim->addressSealed, $claim->id, 'address'))
            ->locale($claim->locale)
            ->send(new ClaimApprovedMail($this->code, $plans->find($claim->planId)?->nameIn($claim->locale) ?? '', $this->validUntil));
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('billing_claim_approved_mail_failed');
        Log::debug('billing_claim_approved_mail_failed_ids', ['claim_id' => $this->claimId]);
    }
}
