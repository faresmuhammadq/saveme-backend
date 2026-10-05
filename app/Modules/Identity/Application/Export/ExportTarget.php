<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Domain\Export\ExportScope;

/**
 * Whose copy is being made and how much of it: the person, and either
 * everything they can see or one wallet.
 */
final readonly class ExportTarget
{
    public function __construct(
        public string $userId,
        public ExportScope $scope,
        public ?string $walletId = null,
    ) {}

    public function isAccount(): bool
    {
        return $this->scope === ExportScope::Account;
    }
}
