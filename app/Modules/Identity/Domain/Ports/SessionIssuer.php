<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Sessions\DeviceDescription;
use App\Modules\Identity\Domain\Sessions\IssuedCredential;

/**
 * Creates a Device Session with its credential, or rotates the credential of
 * an existing active one (spec 007, R-4).
 */
interface SessionIssuer
{
    public function issue(string $userId, DeviceDescription $device): IssuedCredential;

    public function rotate(string $sessionId): IssuedCredential;
}
