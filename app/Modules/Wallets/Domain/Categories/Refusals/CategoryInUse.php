<?php

namespace App\Modules\Wallets\Domain\Categories\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Delete of a category that recorded history references (R-7): archive it
 * instead. Nothing changed.
 */
final class CategoryInUse extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::CategoryInUse);
    }
}
