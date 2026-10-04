<?php

namespace App\Modules\Admin\Domain\Operators\Refusals;

use RuntimeException;

/**
 * A provisioning command that cannot be carried out, with the reason the
 * command prints.
 */
final class OperatorCommandRefused extends RuntimeException
{
    /**
     * @param  'invalid_email'|'name_required'|'duplicate'|'not_found'|'deactivated'|'already_deactivated'  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Operator command refused: {$reason}.");
    }
}
