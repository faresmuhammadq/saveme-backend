<?php

namespace App\Modules\Identity\Application\Dto;

use DateTimeImmutable;

/**
 * The signed-in person's own account, as the app sees it. Not syncable.
 */
final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $emailVerified,
        public string $state,
        public ?DateTimeImmutable $deletionScheduledFor,
    ) {}
}
