<?php

namespace App\Modules\Sharing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Sharing\Application\InvitationService;
use App\Modules\Sharing\Application\SharedBudgets\SharedBudgetInvitationQueryService;
use App\Modules\Sharing\Application\SharedBudgets\SharedBudgetInvitationService;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;
use App\Modules\Sharing\Http\PathIds;
use App\Modules\Sharing\Http\Requests\UpsertSharedBudgetInvitationRequest;
use App\Modules\Sharing\Http\Requests\VersionRequest;
use App\Modules\Sharing\Http\Resources\SharedBudgetInvitationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class SharedBudgetInvitationController
{
    public function index(Request $request, string $id, SharedBudgetInvitationQueryService $invitations): JsonResponse
    {
        $items = $invitations->pending($this->callerId($request), PathIds::wallet($id));

        return Envelope::collection(
            array_map(fn (SharedBudgetInvitationRecord $invitation): array => SharedBudgetInvitationResource::toArray($invitation), $items),
            null,
            max(1, count($items)),
        );
    }

    public function show(Request $request, string $id, string $iid, SharedBudgetInvitationQueryService $invitations): JsonResponse
    {
        return Envelope::ok(SharedBudgetInvitationResource::toArray($invitations->find($this->callerId($request), PathIds::wallet($id), PathIds::invitation($iid))));
    }

    public function upsert(UpsertSharedBudgetInvitationRequest $request, SharedBudgetInvitationService $invitations): JsonResponse
    {
        $saved = $invitations->send($request->callerId(), $request->budgetId(), $request->invitationId(), $request->version(), SharedBudgetInvitationResource::toArray(...));

        $meta = $saved->shareUrl === null ? [] : ['share_url' => $saved->shareUrl];

        return Envelope::ok(SharedBudgetInvitationResource::toArray($saved->invitation), $meta, $saved->created ? 201 : 200)
            ->withHeaders($this->budgetHeaders($request->callerId()));
    }

    public function destroy(VersionRequest $request, SharedBudgetInvitationService $invitations): JsonResponse
    {
        return Envelope::ok(SharedBudgetInvitationResource::toArray($invitations->revoke($request->callerId(), $request->walletId(), $request->invitationId(), $request->version(), SharedBudgetInvitationResource::toArray(...))));
    }

    /**
     * @return array<string, string>
     */
    private function budgetHeaders(string $userId): array
    {
        $key = InvitationService::budgetKey($userId);
        $max = (int) config('sharing.throttle.invites_per_day');

        return [
            'RateLimit-Limit' => (string) $max,
            'RateLimit-Remaining' => (string) RateLimiter::remaining($key, $max),
            'RateLimit-Reset' => (string) RateLimiter::availableIn($key),
        ];
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
