<?php

namespace App\Modules\Billing\Http\Console\Concerns;

use App\Modules\Admin\Application\Operators\OperatorDirectory;
use App\Modules\Shared\Domain\Errors\Refusal;
use Closure;
use Illuminate\Support\Str;

/**
 * What the review commands share (spec 020, FR-025, research R-9): the named
 * operator must be active, and a refusal prints its translated message and
 * exits 1. The command line is the stronger privilege, so it asks for no
 * second factor; the back-office screens do (A-27, A-28).
 */
trait ReviewsAsOperator
{
    /**
     * @param  Closure(string): void  $act  receives the operator id
     */
    private function asActiveOperator(Closure $act): int
    {
        $operatorId = (string) $this->option('operator');

        if (! Str::isUuid($operatorId) || app(OperatorDirectory::class)->view($operatorId)?->state !== 'active') {
            $this->error('The operator must be the id of an active operator account.');

            return self::FAILURE;
        }

        try {
            $act($operatorId);
        } catch (Refusal $refusal) {
            $this->error(__($refusal->detailKey ?? 'problems.'.$refusal->errorCode->value));

            foreach ($refusal->fieldErrors as $error) {
                $this->line("  {$error->pointer}: {$error->code->value}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
