<?php

namespace App\Modules\Notifications\Domain\Delivery;

/**
 * Where a message goes: one registration and its opened push token. Exists
 * only inside the delivery job; never stored, logged or queued (FR-007).
 */
final readonly class PushDestination
{
    public function __construct(
        public string $registrationId,
        #[\SensitiveParameter] public string $token,
    ) {}

    /**
     * Keeps the token out of dumps and exception traces.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['registrationId' => $this->registrationId, 'token' => '[redacted]'];
    }
}
