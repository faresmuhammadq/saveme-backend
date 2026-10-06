<?php

namespace App\Modules\Notifications\Domain\Ports;

interface TokenSealer
{
    /**
     * Seals a push token, binding it to its session.
     */
    public function seal(#[\SensitiveParameter] string $token, string $sessionId): string;

    /**
     * Opens a sealed token; only the delivery job calls this.
     */
    public function open(string $sealed, string $sessionId): string;
}
