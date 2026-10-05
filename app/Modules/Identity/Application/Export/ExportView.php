<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Domain\Export\ExportRecord;
use DateTimeImmutable;

/**
 * An export as a read presents it: the record, and, when it is ready, a
 * freshly signed link to the archive and when that link stops working.
 */
final readonly class ExportView
{
    public function __construct(
        public ExportRecord $export,
        public ?string $downloadUrl = null,
        public ?DateTimeImmutable $downloadUrlExpiresAt = null,
    ) {}
}
