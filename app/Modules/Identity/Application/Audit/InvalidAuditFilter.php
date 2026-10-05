<?php

namespace App\Modules\Identity\Application\Audit;

use RuntimeException;

/**
 * The audit log filter was refused (spec 021, FR-042). `fields` maps each
 * offending field (event, actor_type, actor_id, subject_type, subject_id,
 * from, to) to a short reason code the screen turns into words.
 */
final class InvalidAuditFilter extends RuntimeException
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('The audit filter is invalid: '.implode(', ', array_keys($fields)));
    }
}
