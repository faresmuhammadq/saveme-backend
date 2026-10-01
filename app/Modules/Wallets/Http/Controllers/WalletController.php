<?php

namespace App\Modules\Wallets\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\WalletQueryService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Http\Requests\PatchWalletRequest;
use App\Modules\Wallets\Http\Requests\SaveWalletRequest;
use App\Modules\Wallets\Http\Requests\TransferOwnershipRequest;
use App\Modules\Wallets\Http\Requests\VersionRequest;
use App\Modules\Wallets\Http\Requests\WalletId;
use App\Modules\Wallets\Http\Resources\WalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, WalletQueryService $wallets): JsonResponse
    {
        $query = CollectionQuery::from($request, filterable: [], sortable: ['created_at', 'name'], defaultSort: 'created_at');

        $after = null;
        if ($query->after !== null) {
            $id = $query->after['id'] ?? null;
            $value = $query->after['value'] ?? null;

            if (! is_string($id) || preg_match(self::UUID, $id) !== 1 || ! is_string($value)) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }

            $after = ['value' => $value, 'id' => $id];
        }

        /** @var 'name'|'created_at' $sort */
        $sort = $query->sort;
        $page = $wallets->listFor($this->callerId($request), $sort, $query->descending, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (WalletView $view): array => WalletResource::toArray($view), $page['items']),
            $query->cursorFor($page['next']),
            $query->limit,
        );
    }

    public function show(Request $request, string $id, WalletQueryService $wallets): JsonResponse
    {
        return Envelope::ok(WalletResource::toArray($wallets->find($this->callerId($request), WalletId::assert($id))));
    }

    public function upsert(SaveWalletRequest $request, WalletService $wallets): JsonResponse
    {
        $saved = $wallets->save(
            $this->callerId($request),
            $request->walletId(),
            $request->values(),
            $request->version(),
            WalletResource::toArray(...),
        );

        return Envelope::ok(WalletResource::toArray($saved->view), status: $saved->created ? 201 : 200);
    }

    public function patch(PatchWalletRequest $request, WalletService $wallets): JsonResponse
    {
        $view = $wallets->patch($this->callerId($request), $request->walletId(), $request->values(), $request->version(), WalletResource::toArray(...));

        return Envelope::ok(WalletResource::toArray($view));
    }

    public function archive(VersionRequest $request, WalletService $wallets): JsonResponse
    {
        $view = $wallets->archive($this->callerId($request), $request->walletId(), $request->version(), WalletResource::toArray(...));

        return Envelope::ok(WalletResource::toArray($view));
    }

    public function destroy(VersionRequest $request, WalletService $wallets): JsonResponse
    {
        $view = $wallets->delete($this->callerId($request), $request->walletId(), $request->version(), WalletResource::toArray(...));

        return Envelope::ok(WalletResource::toArray($view));
    }

    public function transferOwnership(TransferOwnershipRequest $request, WalletService $wallets): JsonResponse
    {
        $view = $wallets->transferOwnership(
            $this->callerId($request),
            $request->walletId(),
            $request->targetUserId(),
            $request->version(),
            WalletResource::toArray(...),
        );

        return Envelope::ok(WalletResource::toArray($view));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
