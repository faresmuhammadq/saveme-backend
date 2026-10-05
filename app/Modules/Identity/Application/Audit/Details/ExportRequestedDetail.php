<?php

namespace App\Modules\Identity\Application\Audit\Details;

use App\Modules\Identity\Domain\Export\ExportScope;

/**
 * An `export_requested` entry: the scope only, never a wallet name or id.
 */
final readonly class ExportRequestedDetail implements AuditDetail
{
    public function __construct(private ExportScope $scope) {}

    public function toArray(): array
    {
        return ['scope' => $this->scope->value];
    }
}
