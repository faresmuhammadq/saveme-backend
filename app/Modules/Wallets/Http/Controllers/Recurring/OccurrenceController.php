<?php

namespace App\Modules\Wallets\Http\Controllers\Recurring;

use App\Http\Api\Envelope;
use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceView;
use App\Modules\Wallets\Application\Recurring\OccurrenceService;
use App\Modules\Wallets\Application\Recurring\RecurringQueryService;
use App\Modules\Wallets\Http\Requests\Recurring\OccurrenceId;
use App\Modules\Wallets\Http\Requests\Recurring\OccurrenceListRequest;
use App\Modules\Wallets\Http\Requests\Recurring\SaveOccurrenceRequest;
use App\Modules\Wallets\Http\Requests\WalletId;
use App\Modules\Wallets\Http\Resources\OccurrenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OccurrenceController
{
    public function index(Request $request, string $id, RecurringQueryService $recurring): JsonResponse
    {
        $walletId = WalletId::assert($id);
        $list = OccurrenceListRequest::from($request);

        /** @var 'date'|'created_at' $sort */
        $sort = $list->sort;
        $page = $recurring->listOccurrences($this->callerId($request), $walletId, $list->query->filters, $sort, $list->query->descending, $list->after, $list->query->limit);

        return Envelope::collection(
            array_map(fn (OccurrenceView $view): array => OccurrenceResource::toArray($view), $page['items']),
            $list->query->cursorFor($page['next']),
            $list->query->limit,
        );
    }

    public function show(Request $request, string $id, string $oid, RecurringQueryService $recurring): JsonResponse
    {
        return Envelope::ok(OccurrenceResource::toArray($recurring->findOccurrence($this->callerId($request), WalletId::assert($id), OccurrenceId::assert($oid))));
    }

    public function save(SaveOccurrenceRequest $request, OccurrenceService $occurrences): JsonResponse
    {
        $view = $occurrences->save(
            $this->callerId($request),
            $request->walletId(),
            $request->occurrenceId(),
            $request->values(),
            $request->version(),
            OccurrenceResource::toArray(...),
        );

        return Envelope::ok(OccurrenceResource::toArray($view));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
