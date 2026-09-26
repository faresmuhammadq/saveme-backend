<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * The structured `detail` of one audit entry. Each event has a closed class,
 * so nothing on the exclusion list (addresses, passwords, tokens, amounts,
 * notes) can be passed in as a free-form array (security baseline, What an
 * entry must never contain).
 */
interface AuditDetail
{
    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array;
}
