<?php

namespace App\Modules\Admin\Domain\Operators;

use DateTimeImmutable;

final readonly class ReenrolmentRecord
{
    public function __construct(
        public string $id,
        public string $operatorId,
        public ReenrolmentState $state,
        public DateTimeImmutable $openedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $closedAt,
        public ?string $closedBy,
    ) {}
}
