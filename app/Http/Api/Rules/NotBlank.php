<?php

namespace App\Http\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A member that is present but empty. The request pipeline turns an empty or
 * blank string into null before validation, so "present and null" is how a
 * blank text arrives; the contract names it OUT_OF_RANGE, not REQUIRED, which
 * is for a member that is absent. Place it before `string`, with `bail`.
 */
final class NotBlank implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            $fail('problems.fields.OUT_OF_RANGE')->translate();
        }
    }
}
