<?php

namespace App\Modules\Identity\Domain\Export;

use DateTimeImmutable;

/**
 * An Export as the application sees it: every column, no framework type.
 */
final readonly class ExportRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public ExportScope $scope,
        public ?string $walletId,
        public ExportStatus $status,
        public ?ExportFailure $failure,
        public ?string $filePath,
        public ?int $sizeBytes,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $deliveredAt,
    ) {}
}
