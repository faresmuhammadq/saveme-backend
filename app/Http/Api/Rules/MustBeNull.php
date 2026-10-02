<?php

namespace App\Http\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A member that may only be present and null, such as the `deleted_at` of an
 * undelete. Reported as INVALID_TYPE: the value is not what the member takes.
 */
final class MustBeNull implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null) {
            $fail('problems.fields.INVALID_TYPE')->translate();
        }
    }
}
