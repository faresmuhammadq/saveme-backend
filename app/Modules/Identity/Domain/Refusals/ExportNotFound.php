<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * No such export for this person, or a wallet they cannot export: another
 * person's export is indistinguishable from none.
 */
final class ExportNotFound extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}
