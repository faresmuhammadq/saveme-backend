<?php

namespace App\Modules\Identity\Application\Dto;

/**
 * What Notifications needs to address one person: profile language and
 * timezone, and whether the account may still be written to (active or
 * waiting to be deleted; not purging, purged or unknown).
 */
final readonly class RecipientView
{
    public function __construct(
        public string $locale,
        public string $timezone,
        public bool $active,
    ) {}
}
