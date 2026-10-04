<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Http\Console\Concerns\ReviewsAsOperator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Voids an unredeemed code after a mistaken approval (spec 020, FR-024,
 * FR-025). No mail is sent: the operator contacts the person.
 */
#[Signature('billing:claim:revoke-code {claim : The claim id} {--operator= : The id of an active operator} {--reason= : Why, 1 to 500 characters}')]
#[Description('Revoke the unredeemed redeem code of an approved claim')]
class RevokeClaimCode extends Command
{
    use ReviewsAsOperator;

    public function handle(ClaimService $claims): int
    {
        return $this->asActiveOperator(function (string $operatorId) use ($claims): void {
            $claims->revokeCode($operatorId, (string) $this->argument('claim'), (string) $this->option('reason'));
            $this->components->info('Code revoked.');
        });
    }
}
