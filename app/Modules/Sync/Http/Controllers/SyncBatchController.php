<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Sync\Application\UpsertService;
use App\Modules\Sync\Domain\Batch\BatchItemResult;
use App\Modules\Sync\Http\Requests\SyncBatchRequest;
use Illuminate\Http\JsonResponse;

final class SyncBatchController
{
    public function __invoke(SyncBatchRequest $request, UpsertService $batch): JsonResponse
    {
        $results = $batch->apply($request->items());

        return Envelope::ok(array_map(fn (BatchItemResult $result): array => $result->toArray(), $results));
    }
}
