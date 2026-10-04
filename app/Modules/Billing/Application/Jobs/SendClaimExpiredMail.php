<?php

namespace App\Modules\Billing\Application\Jobs;

use App\Modules\Billing\Application\Mail\ClaimExpiredMail;
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
 * Tells the claimant their claim expired unreviewed (spec 020, FR-016). Names
 * the reference by its last four characters only. A claim whose address was
 * erased sends nothing.
 */
final class SendClaimExpiredMail implements ShouldBeEncrypted, ShouldQueue
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

        if ($claim === null || $claim->addressSealed === null) {
            return;
        }

        Mail::to($sealer->open($claim->addressSealed, $claim->id, 'address'))
            ->locale($claim->locale)
            ->send(new ClaimExpiredMail($claim->id, $plans->find($claim->planId)?->nameIn($claim->locale) ?? '', substr($claim->reference, -4)));
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('billing_claim_expired_mail_failed');
        Log::debug('billing_claim_expired_mail_failed_ids', ['claim_id' => $this->claimId]);
    }
}
