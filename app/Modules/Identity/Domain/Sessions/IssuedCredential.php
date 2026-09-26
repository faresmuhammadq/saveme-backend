<?php

namespace App\Modules\Identity\Domain\Sessions;

/**
 * A session credential at the one moment it is readable: in the response that
 * issues it. `{session_id}|{secret}`; only a hash of the secret is stored.
 */
final readonly class IssuedCredential
{
    public function __construct(
        public string $sessionId,
        public string $plainText,
    ) {}
}
