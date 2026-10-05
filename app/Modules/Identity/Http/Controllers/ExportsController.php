<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Identity\Application\Export\ExportQuery;
use App\Modules\Identity\Application\Export\ExportView;
use App\Modules\Identity\Application\Export\RequestExport;
use App\Modules\Identity\Http\Controllers\Concerns\ResolvesCaller;
use App\Modules\Identity\Http\Requests\RequestExportRequest;
use App\Modules\Identity\Http\Resources\ExportResource;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exports (spec 022): ask for a copy, list the caller's, read one.
 */
final class ExportsController
{
    use ResolvesCaller;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function store(RequestExportRequest $request, RequestExport $exports): JsonResponse
    {
        $view = $exports->handle(
            $this->callerId($request),
            $request->scope(),
            $request->walletId(),
            ExportResource::toArray(...),
        );

        return Envelope::accepted(ExportResource::toArray($view));
    }

    public function index(Request $request, ExportQuery $query): JsonResponse
    {
        $list = CollectionQuery::from($request, filterable: [], sortable: ['requested_at'], defaultSort: 'requested_at', defaultLimit: 20);

        $after = null;

        if ($list->after !== null) {
            $id = $list->after['id'] ?? null;
            $value = $list->after['value'] ?? null;

            if (! is_string($id) || preg_match(self::UUID, $id) !== 1 || ! is_string($value) || strtotime($value) === false) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }

            $after = ['value' => $value, 'id' => $id];
        }

        $page = $query->page($this->callerId($request), $after, $list->limit);

        return Envelope::collection(
            array_map(fn (ExportView $view): array => ExportResource::toArray($view), $page['items']),
            $list->cursorFor($page['next']),
            $list->limit,
        );
    }

    public function show(Request $request, string $id, ExportQuery $query): JsonResponse
    {
        if (preg_match(self::UUID, $id) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidId);
        }

        return Envelope::ok(ExportResource::toArray($query->get($this->callerId($request), $id)));
    }
}
