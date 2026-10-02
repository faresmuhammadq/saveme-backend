<?php

namespace App\Http\Api\Problems;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

/**
 * Turns Laravel's failed validation rules into the contract's field errors:
 * one entry per offending input, each with a JSON Pointer and a field-level
 * catalogue code (docs/api-conventions.md, Validation errors).
 */
final class FieldErrors
{
    /**
     * Rule (lower-case, as Laravel reports it) → field-level code. A rule not
     * listed is reported as INVALID_TYPE: the value is not what the member takes.
     */
    private const RULE_CODES = [
        'required' => ErrorCode::Required,
        'present' => ErrorCode::Required,
        'filled' => ErrorCode::Required,
        'string' => ErrorCode::InvalidType,
        'integer' => ErrorCode::InvalidType,
        'numeric' => ErrorCode::InvalidType,
        'boolean' => ErrorCode::InvalidType,
        'array' => ErrorCode::InvalidType,
        'email' => ErrorCode::InvalidType,
        'regex' => ErrorCode::InvalidType,
        'in' => ErrorCode::InvalidEnumValue,
        'enum' => ErrorCode::InvalidEnumValue,
        'timezone' => ErrorCode::InvalidEnumValue,
        'min' => ErrorCode::OutOfRange,
        'max' => ErrorCode::OutOfRange,
        'between' => ErrorCode::OutOfRange,
        'size' => ErrorCode::OutOfRange,
        'different' => ErrorCode::OutOfRange,
        'uuid' => ErrorCode::InvalidId,
        'prohibited' => ErrorCode::UnknownField,
        'isocurrency' => ErrorCode::InvalidCurrency,
        'minorunits' => ErrorCode::AmountNotInteger,
        'notblank' => ErrorCode::OutOfRange,
        'afterorequal' => ErrorCode::OutOfRange,
        'beforeorequal' => ErrorCode::OutOfRange,
        'calendardate' => ErrorCode::DateNotCalendarDate,
        'uuidv7' => ErrorCode::InvalidId,
    ];

    /**
     * @return list<FieldError>
     */
    public static function fromValidator(Validator $validator): array
    {
        $errors = [];

        foreach ($validator->failed() as $key => $rules) {
            foreach (array_keys($rules) as $rule) {
                $errors[] = new FieldError(self::pointer($key), self::codeFor((string) $rule));
            }
        }

        return $errors;
    }

    public static function codeFor(string $rule): ErrorCode
    {
        $rule = Str::lower(class_basename($rule));

        foreach (self::RULE_CODES as $name => $code) {
            if ($rule === $name) {
                return $code;
            }
        }

        return str_contains($rule, 'password') ? ErrorCode::OutOfRange : ErrorCode::InvalidType;
    }

    /**
     * `device.session_id` → `/device/session_id` (RFC 6901, with ~ and / escaped).
     */
    public static function pointer(string $dottedKey): string
    {
        return '/'.implode('/', array_map(
            fn (string $segment): string => str_replace(['~', '/'], ['~0', '~1'], $segment),
            explode('.', $dottedKey),
        ));
    }
}
