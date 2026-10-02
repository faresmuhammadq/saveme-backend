<?php

namespace App\Modules\Sync\Domain\Ports;

/**
 * Makes a cursor tamper-evident (spec 013, R-9). Implemented in
 * Infrastructure.
 */
interface CursorSigner
{
    /**
     * The signature of the payload, as raw bytes.
     */
    public function sign(string $payload): string;

    /**
     * Whether the signature belongs to the payload, in constant time.
     */
    public function verify(string $payload, string $signature): bool;
}
