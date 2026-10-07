<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Billing\Application\GooglePlay\RestorePlayPurchases;
use App\Modules\Billing\Http\PlayBudget;
use App\Modules\Billing\Http\Requests\RestorePlayPurchasesRequest;
use App\Modules\Billing\Http\Resources\PlayRestoreResultResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /billing/google/restore (spec 024, US5): the in-app "Restore purchases".
 */
final class PlayRestoreController
{
    public function __invoke(RestorePlayPurchasesRequest $request, RestorePlayPurchases $restore): JsonResponse
    {
        PlayBudget::spendRestore($request->callerId());

        return Envelope::ok(PlayRestoreResultResource::toArray($restore->restore($request->callerId(), $request->items(), app()->getLocale())));
    }
}
