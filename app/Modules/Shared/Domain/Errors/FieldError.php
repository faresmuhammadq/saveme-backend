<?php

namespace App\Modules\Shared\Domain\Errors;

/**
 * One invalid input inside a VALIDATION_FAILED problem: where it is (an RFC 6901
 * pointer into the request body) and which field-level code names its fault.
 */
final readonly class FieldError
{
    public function __construct(
        public string $pointer,
        public ErrorCode $code,
        public ?string $detail = null,
    ) {}
}
