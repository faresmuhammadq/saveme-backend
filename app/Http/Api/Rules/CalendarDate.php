<?php

namespace App\Http\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A calendar date: `YYYY-MM-DD`, a real day, no time and no offset (P-12).
 * Reported as the field code DATE_NOT_CALENDAR_DATE.
 */
final class CalendarDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $fail('problems.fields.DATE_NOT_CALENDAR_DATE')->translate();
        }
    }
}
