<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Identity\Application\Dto\DeviceSessionView;
use App\Modules\Identity\Application\Sessions\EndSessions;
use App\Modules\Identity\Application\Sessions\ListSessions;
use App\Modules\Identity\Domain\Sessions\SessionState;
use App\Modules\Identity\Http\Controllers\Concerns\ResolvesCaller;
use App\Modules\Identity\Http\Resources\DeviceSessionResource;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SessionsController
{
    use ResolvesCaller;

    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function index(Request $request, ListSessions $sessions): JsonResponse
    {
        $query = CollectionQuery::from($request, filterable: ['state' => ['eq']], sortable: ['signed_in_at', 'last_used_at'], defaultSort: '-signed_in_at');

        $state = $query->equals('state');
        if ($state !== null && SessionState::tryFrom($state) === null) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        $after = null;
        if ($query->after !== null) {
            $id = $query->after['id'] ?? null;
            $value = $query->after['value'] ?? null;

            if (! is_string($id) || preg_match(self::UUID_V7, $id) !== 1 || ($value !== null && ! is_string($value))) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }

            $after = ['value' => $value, 'id' => $id];
        }

        /** @var 'signed_in_at'|'last_used_at' $sort */
        $sort = $query->sort;
        $page = $sessions->page(
            $this->callerId($request),
            $this->currentSessionId($request),
            $state === null ? null : SessionState::from($state),
            $sort,
            $query->descending,
            $after,
            $query->limit,
        );

        return Envelope::collection(
            array_map(fn (DeviceSessionView $view): array => DeviceSessionResource::toArray($view), $page['items']),
            $query->cursorFor($page['next']),
            $query->limit,
        );
    }

    public function destroy(Request $request, string $id, EndSessions $sessions): Response
    {
        if (preg_match(self::UUID_V7, $id) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidId);
        }

        $sessions->endOne($this->callerId($request), $this->currentSessionId($request), $id);

        return Envelope::noContent();
    }

    public function destroyOthers(Request $request, EndSessions $sessions): Response
    {
        $sessions->endOthers($this->callerId($request), $this->currentSessionId($request));

        return Envelope::noContent();
    }

    public function logout(Request $request, EndSessions $sessions): Response
    {
        $sessions->signOut($this->callerId($request), $this->currentSessionId($request));

        return Envelope::noContent();
    }
}
