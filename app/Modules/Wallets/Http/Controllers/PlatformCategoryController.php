<?php

namespace App\Modules\Wallets\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Wallets\Application\Categories\PlatformCategoryQueryService;
use App\Modules\Wallets\Http\Resources\PlatformCategoryResource;
use Illuminate\Http\JsonResponse;

final class PlatformCategoryController
{
    /**
     * The whole platform set in one page (spec 011, R-10): `next_cursor` is
     * null and `limit` is the row count, clamped to 1-200.
     */
    public function index(PlatformCategoryQueryService $categories): JsonResponse
    {
        $items = array_map(PlatformCategoryResource::toArray(...), $categories->all());

        return Envelope::collection($items, null, max(1, min(count($items), 200)));
    }
}
