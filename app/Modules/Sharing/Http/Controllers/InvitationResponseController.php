<?php

namespace App\Modules\Sharing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Sharing\Application\InvitationResponses;
use App\Modules\Sharing\Http\Requests\InvitationTokenRequest;
use App\Modules\Sharing\Http\Resources\InvitationPreviewResource;
use App\Modules\Sharing\Http\Resources\MemberResource;
use App\Modules\Sharing\Http\TokenBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * What a person holding a token does with it. Open to an unverified account;
 * every call spends the person's 30-per-hour token budget.
 */
final class InvitationResponseController
{
    public function preview(InvitationTokenRequest $request, InvitationResponses $responses): JsonResponse
    {
        $headers = TokenBudget::spend($request->callerId());

        return Envelope::ok(InvitationPreviewResource::toArray($responses->preview($request->callerId(), $request->token())))
            ->withHeaders($headers);
    }

    public function accept(InvitationTokenRequest $request, InvitationResponses $responses): JsonResponse
    {
        $headers = TokenBudget::spend($request->callerId());

        return Envelope::ok(MemberResource::toArray($responses->accept($request->callerId(), $request->token())))
            ->withHeaders($headers);
    }

    public function decline(InvitationTokenRequest $request, InvitationResponses $responses): Response
    {
        $headers = TokenBudget::spend($request->callerId());

        $responses->decline($request->callerId(), $request->token());

        return Envelope::noContent()->withHeaders($headers);
    }
}
