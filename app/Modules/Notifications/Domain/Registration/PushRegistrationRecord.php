<?php

namespace App\Modules\Notifications\Domain\Registration;

use DateTimeImmutable;

/**
 * Where one device session can be reached (spec 023, data-model). The sealed
 * token is opened only by the delivery job, immediately before sending.
 */
final readonly class PushRegistrationRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $sessionId,
        public string $tokenSealed,
        public DateTimeImmutable $registeredAt,
        public DateTimeImmutable $refreshedAt,
    ) {}
}
