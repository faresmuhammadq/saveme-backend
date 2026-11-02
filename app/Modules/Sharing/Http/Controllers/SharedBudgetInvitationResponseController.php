<?php

namespace App\Modules\Sharing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Sharing\Application\SharedBudgets\SharedBudgetInvitationResponses;
use App\Modules\Sharing\Http\Requests\InvitationTokenRequest;
use App\Modules\Sharing\Http\Resources\SharedBudgetInvitationPreviewResource;
use App\Modules\Sharing\Http\Resources\SharedBudgetMembershipResource;
use App\Modules\Sharing\Http\TokenBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * What a person holding a Shared Budget token does with it. Open to an
 * unverified account; every call spends the person's 30-per-hour token budget,
 * the same one a wallet token spends.
 */
final class SharedBudgetInvitationResponseController
{
    public function preview(InvitationTokenRequest $request, SharedBudgetInvitationResponses $responses): JsonResponse
    {
        $headers = TokenBudget::spend($request->callerId());

        return Envelope::ok(SharedBudgetInvitationPreviewResource::toArray($responses->preview($request->callerId(), $request->token())))->withHeaders($headers);
    }

    public function accept(InvitationTokenRequest $request, SharedBudgetInvitationResponses $responses): JsonResponse
    {
        $headers = TokenBudget::spend($request->callerId());
        [$member, $displayName] = $responses->accept($request->callerId(), $request->token());

        return Envelope::ok(SharedBudgetMembershipResource::toArray($member, $displayName))->withHeaders($headers);
    }

    public function decline(InvitationTokenRequest $request, SharedBudgetInvitationResponses $responses): Response
    {
        $headers = TokenBudget::spend($request->callerId());

        $responses->decline($request->callerId(), $request->token());

        return Envelope::noContent()->withHeaders($headers);
    }
}
