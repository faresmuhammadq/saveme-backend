<?php

namespace App\Modules\Billing\Http\Console;

use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The review queue on the command line (spec 020, FR-025): submitted claims,
 * oldest first. Never an address.
 */
#[Signature('billing:claim:list {--limit=50 : How many claims to show}')]
#[Description('List InstaPay claims waiting for review (never shows an address)')]
class ListClaims extends Command
{
    public function handle(ClaimQuery $query, PlanRepository $plans): int
    {
        $claims = array_values(array_filter(
            $query->queue(null, max(1, (int) $this->option('limit'))),
            fn ($claim): bool => $claim->status === 'submitted',
        ));

        if ($claims === []) {
            $this->components->info('No claims are waiting for review.');

            return self::SUCCESS;
        }

        $this->table(
            ['Claim', 'Plan', 'Amount', 'Reference', 'Created', 'Same reference'],
            array_map(fn ($claim): array => [
                $claim->id,
                $plans->find($claim->planId)?->nameIn('en') ?? '?',
                number_format($claim->amountMinor / 100, 2, '.', '').' '.$claim->currency,
                $claim->reference,
                $claim->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'),
                $claim->duplicateCount,
            ], $claims),
        );

        return self::SUCCESS;
    }
}
