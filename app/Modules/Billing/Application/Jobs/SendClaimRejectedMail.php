<?php

namespace App\Modules\Billing\Application\Jobs;

use App\Modules\Billing\Application\Mail\ClaimRejectedMail;
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
 * Tells the claimant why their claim was rejected, in the claim's language
 * (spec 020, FR-018). The reason is read from the claim at send time, so the
 * payload holds only an id; the internal note is never read. A claim whose
 * address was erased sends nothing.
 */
final class SendClaimRejectedMail implements ShouldBeEncrypted, ShouldQueue
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

    public function __construct(public readonly string $claimId) {}

    public function handle(ClaimRepository $claims, ClaimSealer $sealer, PlanRepository $plans): void
    {
        $claim = $claims->find($this->claimId);

        if ($claim === null || $claim->addressSealed === null || $claim->rejectReason === null) {
            return;
        }

        Mail::to($sealer->open($claim->addressSealed, $claim->id, 'address'))
            ->locale($claim->locale)
            ->send(new ClaimRejectedMail($claim->id, $plans->find($claim->planId)?->nameIn($claim->locale) ?? '', $claim->rejectReason->value));
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('billing_claim_rejected_mail_failed');
        Log::debug('billing_claim_rejected_mail_failed_ids', ['claim_id' => $this->claimId]);
    }
}
