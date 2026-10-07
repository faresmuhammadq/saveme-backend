<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /billing/google/account-reference (spec 024, FR-005): the opaque value
 * the app passes to Play Billing as the obfuscated account id. Created on
 * first request; only the caller's own is ever returned.
 */
final class PlayAccountReferenceController
{
    public function __invoke(Request $request, PlayAccountReferences $references): JsonResponse
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return Envelope::ok(['obfuscated_account_id' => $references->for((string) $user->getAuthIdentifier())]);
    }
}
