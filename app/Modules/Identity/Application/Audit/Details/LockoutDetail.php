<?php

namespace App\Modules\Identity\Application\Audit\Details;

final readonly class LockoutDetail implements AuditDetail
{
    public function __construct(private int $minutes, private ?string $lookupPrefix = null) {}

    public function toArray(): array
    {
        return array_filter(['minutes' => $this->minutes, 'lookup_prefix' => $this->lookupPrefix], fn ($value) => $value !== null);
    }
}
