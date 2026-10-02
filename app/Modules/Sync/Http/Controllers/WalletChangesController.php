<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Sync\Application\ChangeFeedService;
use App\Modules\Sync\Http\Requests\FeedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class WalletChangesController
{
    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __invoke(Request $request, string $id, ChangeFeedService $feeds): JsonResponse
    {
        if (preg_match(self::UUID_V7, $id) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidId);
        }

        $query = FeedRequest::from($request);
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        return FeedResponse::from($feeds->walletPage((string) $user->getAuthIdentifier(), $id, $query->cursor, $query->limit));
    }
}
