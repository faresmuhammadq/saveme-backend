<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Billing\Domain\Feature;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The wallet owner's plan does not grant the capability (P-09 rule 2). It
 * names the capability when it is known, and the problem carries it as
 * `feature` (v1.9), so a client can tell "no sharing in this plan" from
 * "the wallet is full".
 */
final class FeatureNotEntitled extends Refusal
{
    public function __construct(public readonly ?Feature $feature = null)
    {
        parent::__construct(ErrorCode::FeatureNotEntitled, extensions: $feature === null ? [] : ['feature' => $feature->value]);
    }
}
