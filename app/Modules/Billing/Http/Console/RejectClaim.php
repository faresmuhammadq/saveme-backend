<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Http\Console\Concerns\ReviewsAsOperator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rejects a claim as an active operator (spec 020, FR-018, FR-025).
 */
#[Signature('billing:claim:reject {claim : The claim id} {--operator= : The id of an active operator} {--reason= : reference_not_found, amount_mismatch, duplicate or other} {--note= : An internal note, at most 500 characters; never mailed}')]
#[Description('Reject an InstaPay claim with a reason; the claimant is told the reason by mail')]
class RejectClaim extends Command
{
    use ReviewsAsOperator;

    public function handle(ClaimService $claims): int
    {
        return $this->asActiveOperator(function (string $operatorId) use ($claims): void {
            $note = $this->option('note');
            $claims->reject($operatorId, (string) $this->argument('claim'), (string) $this->option('reason'), is_string($note) ? $note : null);
            $this->components->info('Claim rejected. The claimant was told the reason by mail.');
        });
    }
}
