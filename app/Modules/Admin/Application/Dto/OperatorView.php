<?php

namespace App\Modules\Admin\Application\Dto;

/**
 * An operator as a screen shows them: scalars only (P-18 rule 5). Instants
 * are ISO 8601 strings in UTC.
 */
final readonly class OperatorView
{
    public function __construct(
        public string $id,
        public string $displayName,
        public string $state,
        public ?string $enrolledAt,
        public int $recoveryCodesLeft,
        public ?string $lastSignedInAt,
    ) {}
}
