<?php

namespace App\Modules\Wallets\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The named person is not another active member of the wallet (spec 010,
 * FR-017). The allowed set of `user_id` values is the wallet's other active
 * members, so the field code is INVALID_ENUM_VALUE (no new catalogue code).
 */
final class OwnershipTargetInvalid extends Refusal
{
    public function __construct()
    {
        parent::__construct(
            ErrorCode::ValidationFailed,
            fieldErrors: [new FieldError('/user_id', ErrorCode::InvalidEnumValue)],
            detailKey: 'problems.wallet_ownership_target',
        );
    }
}
