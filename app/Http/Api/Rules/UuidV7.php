<?php

namespace App\Http\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A lowercase hyphenated UUIDv7, the only identifier a client generates
 * (P-06 rule 1). Reported as the field code INVALID_ID.
 */
final class UuidV7 implements ValidationRule
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('problems.fields.INVALID_ID')->translate();
        }
    }
}
