<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The default currency cannot change once the person has recorded a live
 * transaction (spec 008, FR-010; owner decision 2026-09-27).
 */
final class DefaultCurrencyLocked extends Refusal
{
    public function __construct(string $detail)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [
            new FieldError('/default_currency', ErrorCode::ImmutableField, $detail),
        ]);
    }
}
