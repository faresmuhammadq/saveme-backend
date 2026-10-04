<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Http\Console\Concerns\ReviewsAsOperator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Approves a claim as an active operator (spec 020, FR-025; the C11
 * integration checkpoint). The code goes by mail only and is never printed.
 */
#[Signature('billing:claim:approve {claim : The claim id} {--operator= : The id of an active operator}')]
#[Description('Approve an InstaPay claim; the redeem code is mailed to the claim\'s address')]
class ApproveClaim extends Command
{
    use ReviewsAsOperator;

    public function handle(ClaimService $claims): int
    {
        return $this->asActiveOperator(function (string $operatorId) use ($claims): void {
            $claims->approve($operatorId, (string) $this->argument('claim'));
            $this->components->info('Claim approved. The code was mailed to the claim\'s address.');
        });
    }
}
