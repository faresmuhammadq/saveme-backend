<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Billing\Application\GooglePlay\VerifyPlayPurchase;
use App\Modules\Billing\Http\PlayBudget;
use App\Modules\Billing\Http\Requests\VerifyPlayPurchaseRequest;
use App\Modules\Billing\Http\Resources\PlayPurchaseResultResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /billing/google/purchases (spec 024, US1): the token is never echoed
 * and never logged. 200 when linked, 202 while Google says pending.
 */
final class PlayPurchaseController
{
    public function __invoke(VerifyPlayPurchaseRequest $request, VerifyPlayPurchase $verify): JsonResponse
    {
        PlayBudget::spendVerify($request->callerId());

        $result = $verify->verify($request->callerId(), $request->purchaseToken(), $request->productId(), app()->getLocale());

        return Envelope::ok(PlayPurchaseResultResource::toArray($result), status: $result['pending'] ? 202 : 200);
    }
}
