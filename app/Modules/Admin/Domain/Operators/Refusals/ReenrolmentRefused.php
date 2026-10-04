<?php

namespace App\Modules\Admin\Domain\Operators\Refusals;

use RuntimeException;

final class ReenrolmentRefused extends RuntimeException
{
    /**
     * @param  'own_request'|'already_approved'|'expired'|'closed'|'already_open'  $reason
     */
    public function __construct(public readonly string $reason, public readonly ?\DateTimeImmutable $expiresAt = null)
    {
        parent::__construct("Re-enrolment refused: {$reason}.");
    }
}
