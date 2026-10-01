<?php

namespace App\Modules\Wallets\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The type changed, or the currency changed after a posting references the
 * wallet (spec 010, FR-002, FR-004): IMMUTABLE_FIELD at each offending member.
 */
final class WalletFieldImmutable extends Refusal
{
    /**
     * @param  list<string>  $pointers  `/type` and/or `/currency`
     */
    public function __construct(array $pointers)
    {
        parent::__construct(
            ErrorCode::ValidationFailed,
            fieldErrors: array_map(fn (string $pointer): FieldError => new FieldError($pointer, ErrorCode::ImmutableField), $pointers),
            detailKey: in_array('/type', $pointers, true) ? 'problems.wallet_type_immutable' : 'problems.wallet_currency_locked',
        );
    }
}
