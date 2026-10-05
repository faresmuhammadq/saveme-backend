<?php

namespace App\Modules\Identity\Domain\Deletion;

/**
 * The member who will own a departing person's shared wallet, and why.
 */
final readonly class Successor
{
    public function __construct(
        public string $userId,
        public SuccessorReason $reason,
    ) {}
}
