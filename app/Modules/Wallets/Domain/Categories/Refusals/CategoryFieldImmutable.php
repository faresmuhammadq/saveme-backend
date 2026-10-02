<?php

namespace App\Modules\Wallets\Domain\Categories\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The kind changed (spec 011, FR-002): IMMUTABLE_FIELD at `/kind`.
 */
final class CategoryFieldImmutable extends Refusal
{
    public function __construct()
    {
        parent::__construct(
            ErrorCode::ValidationFailed,
            fieldErrors: [new FieldError('/kind', ErrorCode::ImmutableField)],
            detailKey: 'problems.category_kind_immutable',
        );
    }
}
