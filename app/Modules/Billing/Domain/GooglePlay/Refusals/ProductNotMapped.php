<?php

namespace App\Modules\Billing\Domain\GooglePlay\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The posted product id maps to no plan (spec 024, FR-042): INVALID_ENUM_VALUE at /product_id.
 */
final class ProductNotMapped extends Refusal
{
    public function __construct(string $pointer = '/product_id')
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError($pointer, ErrorCode::InvalidEnumValue)]);
    }
}
