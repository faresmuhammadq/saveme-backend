<?php

namespace App\Modules\Shared\Domain\Errors;

use RuntimeException;

/**
 * A request the application refuses on purpose, carrying the catalogue code
 * that names the refusal. The HTTP runtime renders every Refusal as a problem
 * body with the code's own status; nothing hand-builds a problem response.
 *
 * Modules extend this with one small class per refusal, so a domain rule
 * throws a meaningful type while the wire answer stays a catalogue code.
 */
abstract class Refusal extends RuntimeException
{
    /**
     * @param  list<FieldError>  $fieldErrors
     * @param  array<string, scalar|array<mixed>>  $extensions  flat problem members, e.g. minimum_version, or current on a version conflict
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $fieldErrors = [],
        public readonly array $extensions = [],
        public readonly ?string $detailKey = null,
    ) {
        parent::__construct($errorCode->value);
    }
}
