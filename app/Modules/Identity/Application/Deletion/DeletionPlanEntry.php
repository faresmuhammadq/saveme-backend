<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Domain\Deletion\Successor;

/**
 * Who would own one shared wallet if the account were deleted now.
 */
final readonly class DeletionPlanEntry
{
    public function __construct(
        public string $walletId,
        public Successor $successor,
        public bool $choiceExpected,
    ) {}
}
