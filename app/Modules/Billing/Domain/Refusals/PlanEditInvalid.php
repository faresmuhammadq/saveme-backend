<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A plan edit breaks a rule (spec 021, FR-032, FR-033). `$fields` names each
 * field at fault: `name.en`, `name.ar`, `sort_order`, `prices.instapay`,
 * `features.<key>`.
 */
final class PlanEditInvalid extends Refusal
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct(
            ErrorCode::ValidationFailed,
            fieldErrors: array_map(fn (string $field): FieldError => new FieldError('/'.str_replace('.', '/', $field), ErrorCode::OutOfRange), $fields),
        );
    }
}
