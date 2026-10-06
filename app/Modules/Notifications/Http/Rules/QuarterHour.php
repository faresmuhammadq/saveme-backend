<?php

namespace App\Modules\Notifications\Http\Rules;

use App\Modules\Notifications\Domain\Preferences\QuietHours;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A 24-hour `HH:MM` on a quarter hour (00, 15, 30, 45). "23:10" and "24:00"
 * are out of range. Place it after `string`, with `bail`.
 */
final class QuarterHour implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || QuietHours::parse($value) === null) {
            $fail('problems.fields.OUT_OF_RANGE')->translate();
        }
    }
}
