<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * Seals and opens a person's protected attributes (address, display name)
 * under their own data key (security baseline, What is protected at rest;
 * spec 007 R-6). Destroying the data key makes every sealed value of that
 * person unreadable, backups included.
 */
interface AttributeCipher
{
    public function newDataKeyFor(string $userId): void;

    public function seal(string $userId, string $plaintext): string;

    public function open(string $userId, string $sealed): string;
}
