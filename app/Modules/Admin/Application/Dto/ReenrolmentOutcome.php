<?php

namespace App\Modules\Admin\Application\Dto;

final readonly class ReenrolmentOutcome
{
    public function __construct(
        public string $requestId,
        public string $expiresAt,
        public bool $alreadyOpen,
        public bool $enoughApprovers,
    ) {}
}
