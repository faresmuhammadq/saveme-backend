<?php

namespace App\Modules\Billing\Domain\Idempotency;

use stdClass;

/**
 * The fingerprint of a request for Idempotency-Key (spec 020, R-2): SHA-256
 * of `METHOD`, path and the canonical JSON body, where object keys are
 * sorted at every depth, lists keep their order, and there is no whitespace.
 * Two bodies that differ only in key order or spacing give the same
 * fingerprint; a body that is not JSON is hashed as sent.
 */
final class CanonicalJson
{
    public static function fingerprint(string $method, string $path, string $rawBody): string
    {
        return hash('sha256', strtoupper($method)."\n".$path."\n".self::canonical($rawBody));
    }

    public static function canonical(string $rawBody): string
    {
        $decoded = json_decode($rawBody);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $rawBody;
        }

        return (string) json_encode(self::sorted($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = (array) $value;
            ksort($members, SORT_STRING);

            return (object) array_map(self::sorted(...), $members);
        }

        if (is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        return $value;
    }
}
