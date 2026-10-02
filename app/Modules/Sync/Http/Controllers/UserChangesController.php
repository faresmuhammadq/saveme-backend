<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Modules\Sync\Application\ChangeFeedService;
use App\Modules\Sync\Http\Requests\FeedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class UserChangesController
{
    public function __invoke(Request $request, ChangeFeedService $feeds): JsonResponse
    {
        $query = FeedRequest::from($request);
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');
        $token = $user->currentAccessToken();

        $result = $feeds->userPage((string) $user->getAuthIdentifier(), (string) $token->getKey(), $query->cursor, $query->limit);

        return FeedResponse::from($result);
    }
}
