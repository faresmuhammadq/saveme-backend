<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Http\ClaimBudget;
use App\Modules\Billing\Http\Requests\SubmitClaimRequest;
use App\Modules\Billing\Http\Resources\ClaimReceiptResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /billing/instapay/claims (spec 020): public, website only, answered
 * the same whether or not the address has an account. Nothing here logs the
 * request body.
 */
final class InstapayClaimController
{
    public function __invoke(SubmitClaimRequest $request, ClaimService $claims): JsonResponse
    {
        ClaimBudget::spend($request->ip(), $request->addressLookup());

        return Envelope::accepted(ClaimReceiptResource::toArray($claims->submit($request->toCommand())));
    }
}
