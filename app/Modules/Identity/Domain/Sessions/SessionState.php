<?php

namespace App\Modules\Identity\Domain\Sessions;

/**
 * A Device Session's lifecycle (glossary): active → revoked, active → expired.
 * Both ends are terminal; an ended session never becomes active again.
 */
enum SessionState: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }

    public function canBecome(self $next): bool
    {
        return $this === self::Active && $next !== self::Active;
    }
}
