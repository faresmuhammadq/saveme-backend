<?php

namespace App\Http\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount in minor units: a JSON integer, never a string or a decimal
 * (ADR-0003). Reported as the field code AMOUNT_NOT_INTEGER.
 */
final class MinorUnits implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail('problems.fields.AMOUNT_NOT_INTEGER')->translate();
        }
    }
}
