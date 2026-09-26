<?php

namespace App\Modules\Identity\Application\Audit\Details;

final readonly class PasswordChangedDetail implements AuditDetail
{
    /**
     * @param  'change'|'reset'  $via
     */
    public function __construct(private string $via) {}

    public function toArray(): array
    {
        return ['via' => $this->via];
    }
}
