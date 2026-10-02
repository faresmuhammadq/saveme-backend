<?php

namespace App\Modules\Sharing\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Sharing\Application\Dto\InvitationView;
use App\Modules\Sharing\Application\InvitationQueryService;
use App\Modules\Sharing\Application\InvitationService;
use App\Modules\Sharing\Http\PathIds;
use App\Modules\Sharing\Http\Requests\UpsertInvitationRequest;
use App\Modules\Sharing\Http\Requests\VersionRequest;
use App\Modules\Sharing\Http\Resources\InvitationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class InvitationController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, string $id, InvitationQueryService $invitations): JsonResponse
    {
        $walletId = PathIds::wallet($id);

        if ($request->query->has('sort')) {
            throw new ApiRefusal(ErrorCode::SortFieldNotSupported);
        }

        $query = CollectionQuery::from($request, filterable: [], sortable: ['created_at'], defaultSort: 'created_at');

        $after = null;
        if ($query->after !== null) {
            $cursorId = $query->after['id'] ?? null;
            $value = $query->after['value'] ?? null;

            if (! is_string($cursorId) || preg_match(self::UUID, $cursorId) !== 1 || ! is_string($value)) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }

            $after = ['value' => $value, 'id' => $cursorId];
        }

        $page = $invitations->list($this->callerId($request), $walletId, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (InvitationView $view): array => InvitationResource::toArray($view), $page->items),
            $query->cursorFor($page->next),
            $query->limit,
        );
    }

    public function show(Request $request, string $id, string $iid, InvitationQueryService $invitations): JsonResponse
    {
        return Envelope::ok(InvitationResource::toArray($invitations->find($this->callerId($request), PathIds::wallet($id), PathIds::invitation($iid))));
    }

    public function upsert(UpsertInvitationRequest $request, InvitationService $invitations): JsonResponse
    {
        $saved = $invitations->send(
            $request->callerId(),
            $request->walletId(),
            $request->invitationId(),
            $request->role(),
            $request->inviteeEmail(),
            $request->version(),
            InvitationResource::toArray(...),
        );

        $meta = $saved->shareUrl === null ? [] : ['share_url' => $saved->shareUrl];

        return Envelope::ok(InvitationResource::toArray($saved->invitation), $meta, $saved->created ? 201 : 200)
            ->withHeaders($this->budgetHeaders($request->callerId()));
    }

    public function destroy(VersionRequest $request, InvitationService $invitations): JsonResponse
    {
        $view = $invitations->revoke($request->callerId(), $request->walletId(), $request->invitationId(), $request->version(), InvitationResource::toArray(...));

        return Envelope::ok(InvitationResource::toArray($view));
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
