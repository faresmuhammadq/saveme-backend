<?php

namespace App\Modules\Notifications\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A JSON boolean and nothing else: not "true", not 1. Reported as the field
 * code INVALID_TYPE.
 */
final class StrictBoolean implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail('problems.fields.INVALID_TYPE')->translate();
        }
    }
}
