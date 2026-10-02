<?php

namespace App\Modules\Wallets\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\CategoryQueryService;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Http\Requests\CategoryId;
use App\Modules\Wallets\Http\Requests\CategoryVersionRequest;
use App\Modules\Wallets\Http\Requests\PatchCategoryRequest;
use App\Modules\Wallets\Http\Requests\SaveCategoryRequest;
use App\Modules\Wallets\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, CategoryQueryService $categories): JsonResponse
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
        $page = $categories->listFor($this->callerId($request), $sort, $query->descending, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (CategoryView $view): array => CategoryResource::toArray($view), $page['items']),
            $query->cursorFor($page['next']),
            $query->limit,
        );
    }

    public function show(Request $request, string $id, CategoryQueryService $categories): JsonResponse
    {
        return Envelope::ok(CategoryResource::toArray($categories->find($this->callerId($request), CategoryId::assert($id))));
    }

    public function upsert(SaveCategoryRequest $request, CategoryService $categories): JsonResponse
    {
        $saved = $categories->save(
            $this->callerId($request),
            $request->categoryId(),
            $request->values(),
            $request->version(),
            CategoryResource::toArray(...),
        );

        return Envelope::ok(CategoryResource::toArray($saved->view), status: $saved->created ? 201 : 200);
    }

    public function patch(PatchCategoryRequest $request, CategoryService $categories): JsonResponse
    {
        $view = $categories->patch($this->callerId($request), $request->categoryId(), $request->values(), $request->version(), CategoryResource::toArray(...));

        return Envelope::ok(CategoryResource::toArray($view));
    }

    public function archive(CategoryVersionRequest $request, CategoryService $categories): JsonResponse
    {
        $view = $categories->archive($this->callerId($request), $request->categoryId(), $request->version(), CategoryResource::toArray(...));

        return Envelope::ok(CategoryResource::toArray($view));
    }

    public function destroy(CategoryVersionRequest $request, CategoryService $categories): JsonResponse
    {
        $view = $categories->delete($this->callerId($request), $request->categoryId(), $request->version(), CategoryResource::toArray(...));

        return Envelope::ok(CategoryResource::toArray($view));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
