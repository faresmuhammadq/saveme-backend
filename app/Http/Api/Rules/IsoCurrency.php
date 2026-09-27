<?php

namespace App\Http\Api\Rules;

use App\Modules\Shared\Domain\Money\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An uppercase, active ISO 4217 code. Reported as the field code
 * INVALID_CURRENCY (spec 003); lower case is refused, never corrected.
 */
final class IsoCurrency implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Currency::isKnown($value)) {
            $fail('problems.fields.INVALID_CURRENCY')->translate();
        }
    }
}
