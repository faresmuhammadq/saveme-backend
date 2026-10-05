<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * An `export_delivered` entry: the export by identifier, never a link.
 */
final readonly class ExportDeliveredDetail implements AuditDetail
{
    public function __construct(private string $exportId) {}

    public function toArray(): array
    {
        return ['export_id' => $this->exportId];
    }
}
