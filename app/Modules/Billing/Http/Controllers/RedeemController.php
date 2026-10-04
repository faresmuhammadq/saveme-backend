<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Billing\Application\RedeemCodes\RedeemCodes;
use App\Modules\Billing\Http\RedeemBudget;
use App\Modules\Billing\Http\Requests\RedeemCodeRequest;
use App\Modules\Billing\Http\Resources\RedeemResultResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /billing/redeem (spec 020): the in-app "Enter code". The code is never
 * logged and never echoed.
 */
final class RedeemController
{
    public function __invoke(RedeemCodeRequest $request, RedeemCodes $redeem): JsonResponse
    {
        RedeemBudget::spend($request->callerId());

        return Envelope::ok(RedeemResultResource::toArray($redeem->redeem($request->callerId(), $request->code(), app()->getLocale())));
    }
}
