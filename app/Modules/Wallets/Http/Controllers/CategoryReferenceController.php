<?php

namespace App\Modules\Wallets\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;
use App\Modules\Wallets\Http\Requests\WalletId;
use App\Modules\Wallets\Http\Resources\CategoryReferenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class CategoryReferenceController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, string $id, CategoryReferences $references): JsonResponse
    {
        $walletId = WalletId::assert($id);

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

        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');
        $page = $references->listFor((string) $user->getAuthIdentifier(), $walletId, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (CategoryReferenceRecord $reference): array => CategoryReferenceResource::toArray($reference), $page['items']),
            $query->cursorFor($page['next']),
            $query->limit,
        );
    }
}
