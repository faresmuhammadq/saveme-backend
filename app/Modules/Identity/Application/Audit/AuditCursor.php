<?php

namespace App\Modules\Identity\Application\Audit;

use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * A position in the audit log: the `(occurred_at, id)` of an entry (spec 021,
 * FR-041). Opaque to the screen; a token that does not decode is no cursor.
 */
final readonly class AuditCursor
{
    public function __construct(public string $occurredAt, public string $id) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['t' => $this->occurredAt, 'i' => $this->id])), '+/', '-_'), '=');
    }

    public static function decode(string $token): ?self
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $parts = $json === false ? null : json_decode($json, true);

        if (! is_array($parts) || ! is_string($parts['t'] ?? null) || ! is_string($parts['i'] ?? null)) {
            return null;
        }

        if (! Str::isUuid($parts['i']) || DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $parts['t']) === false) {
            return null;
        }

        return new self($parts['t'], strtolower($parts['i']));
    }
}
