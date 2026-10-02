<?php

namespace App\Modules\Wallets\Domain\Membership\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The owner's membership cannot be given or taken through the membership
 * writes: ownership moves only through the transfer (W-05). A role change
 * that names the owner, or names `owner`, is refused at `/role`.
 */
final class OwnerMembershipImmutable extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError('/role', ErrorCode::ImmutableField)]);
    }
}
