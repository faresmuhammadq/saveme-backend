<?php

namespace App\Modules\Wallets\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Application\Transactions\TransactionListQuery;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Http\Requests\PatchTransactionRequest;
use App\Modules\Wallets\Http\Requests\SaveTransactionRequest;
use App\Modules\Wallets\Http\Requests\TransactionId;
use App\Modules\Wallets\Http\Requests\TransactionListRequest;
use App\Modules\Wallets\Http\Requests\TransactionVersionRequest;
use App\Modules\Wallets\Http\Requests\WalletId;
use App\Modules\Wallets\Http\Resources\TransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransactionController
{
    public function index(Request $request, string $id, TransactionListQuery $transactions): JsonResponse
    {
        $walletId = WalletId::assert($id);
        $list = TransactionListRequest::from($request);

        /** @var 'date'|'created_at' $sort */
        $sort = $list->sort;
        $page = $transactions->list($this->callerId($request), $walletId, $list->query->filters, $list->search, $sort, $list->query->descending, $list->after, $list->query->limit);

        return Envelope::collection(
            array_map(fn (TransactionView $view): array => TransactionResource::toArray($view), $page['items']),
            $list->query->cursorFor($page['next']),
            $list->query->limit,
        );
    }

    public function show(Request $request, string $id, string $tid, TransactionListQuery $transactions): JsonResponse
    {
        $walletId = WalletId::assert($id);
        $transactionId = TransactionId::assert($tid);

        return Envelope::ok(TransactionResource::toArray($transactions->find($this->callerId($request), $walletId, $transactionId)));
    }

    public function upsert(SaveTransactionRequest $request, TransactionService $transactions): JsonResponse
    {
        $saved = $transactions->save(
            $this->callerId($request),
            $request->walletId(),
            $request->transactionId(),
            $request->values(),
            $request->version(),
            TransactionResource::toArray(...),
        );

        return Envelope::ok(TransactionResource::toArray($saved->view), status: $saved->created ? 201 : 200);
    }

    public function patch(PatchTransactionRequest $request, TransactionService $transactions): JsonResponse
    {
        $view = $request->isRestore()
            ? $transactions->restore($this->callerId($request), $request->walletId(), $request->transactionId(), $request->version(), TransactionResource::toArray(...))
            : $transactions->patch($this->callerId($request), $request->walletId(), $request->transactionId(), $request->values(), $request->version(), TransactionResource::toArray(...));

        return Envelope::ok(TransactionResource::toArray($view));
    }

    public function destroy(TransactionVersionRequest $request, TransactionService $transactions): JsonResponse
    {
        $view = $transactions->delete($this->callerId($request), $request->walletId(), $request->transactionId(), $request->version(), TransactionResource::toArray(...));

        return Envelope::ok(TransactionResource::toArray($view));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
