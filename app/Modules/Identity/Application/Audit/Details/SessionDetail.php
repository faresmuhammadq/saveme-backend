<?php

namespace App\Modules\Identity\Application\Audit\Details;

final readonly class SessionDetail implements AuditDetail
{
    public function __construct(private string $platform) {}

    public function toArray(): array
    {
        return ['platform' => $this->platform];
    }
}
