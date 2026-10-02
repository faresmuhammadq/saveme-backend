<?php

namespace App\Modules\Sync\Http;

use App\Http\Api\Problems\ApiRefusal;
use App\Http\Api\Problems\ProblemRenderer;
use App\Modules\Identity\Application\Profiles\Profiles;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Sync\Domain\Batch\BatchItem;
use App\Modules\Sync\Domain\Batch\ItemOperation;
use App\Modules\Sync\Domain\Batch\ItemType;
use App\Modules\Sync\Domain\Ports\ItemDispatcher;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * Performs an item as the per-record write it names (spec 013, R-2, ADR-0016
 * rule 1): builds the equivalent single request, runs it through the router
 * to the existing route with that route's own middleware (`auth:api`,
 * `address.verified`), form request, controller and problem renderer, and
 * hands back the status and JSON body it answered. Sync names routes, never
 * another module's controller (P-02).
 *
 * The sub-request carries the batch caller (authenticated once, for the
 * whole batch) and the client headers. Around each item the dispatcher keeps
 * the parent request intact: the container's request, the current route, the
 * locale and the request id are put back, whatever the item did. An
 * exception that escapes the write is rendered as that item's problem and
 * never leaves the loop.
 */
final class RouterItemDispatcher implements ItemDispatcher
{
    public function __construct(
        private readonly Router $router,
        private readonly Profiles $profiles,
        private readonly ProblemRenderer $problems,
    ) {}

    public function dispatch(BatchItem $item): array
    {
        $parent = app('request');
        $user = $parent->user();
        $batchRequestId = Context::get('request_id');
        $locale = app()->getLocale();
        $parentRoute = $parent->route();
        $currentRoute = $this->router->current();

        [$method, $path] = $this->target($item);
        $sub = $this->subRequest($parent, $method, $path, $item, $locale);

        app()->instance('request', $sub);
        Facade::clearResolvedInstance('request');
        $sub->setUserResolver(fn () => $user);

        try {
            $this->assertOwnProfile($item, $user === null ? null : (string) $user->getAuthIdentifier());

            $response = $this->router->dispatchToRoute($sub);
            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getContent(), true);
        } catch (Throwable $e) {
            report($e);
            Context::add('request_id', $batchRequestId);

            $response = $this->problems->render($e, $sub);
            $status = $response->getStatusCode();
            $body = $response->getData(true);
        } finally {
            app()->instance('request', $parent);
            Facade::clearResolvedInstance('request');
            app()->setLocale($locale);
            Context::add('request_id', $batchRequestId);
            $this->restoreRoute($parentRoute, $currentRoute);
        }

        $body = is_array($body) ? $body : [];

        // One id joins the whole batch in the logs (A-27).
        if ($status >= 400) {
            $body['request_id'] = $batchRequestId;
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @return array{string, string} the HTTP method and path of the single write
     */
    private function target(BatchItem $item): array
    {
        return match ([$item->type, $item->operation]) {
            [ItemType::Wallet, ItemOperation::Upsert] => ['PUT', $this->path('wallets.upsert', ['id' => $item->id])],
            [ItemType::Wallet, ItemOperation::Delete] => ['DELETE', $this->path('wallets.destroy', ['id' => $item->id])],
            [ItemType::Category, ItemOperation::Upsert] => ['PUT', $this->path('categories.upsert', ['id' => $item->id])],
            [ItemType::Category, ItemOperation::Delete] => ['DELETE', $this->path('categories.destroy', ['id' => $item->id])],
            [ItemType::Transaction, ItemOperation::Upsert] => ['PUT', $this->path('transactions.upsert', ['id' => $item->walletId, 'tid' => $item->id])],
            [ItemType::Transaction, ItemOperation::Delete] => ['DELETE', $this->path('transactions.destroy', ['id' => $item->walletId, 'tid' => $item->id])],
            [ItemType::Transaction, ItemOperation::Restore] => ['PATCH', $this->path('transactions.patch', ['id' => $item->walletId, 'tid' => $item->id])],
            [ItemType::Profile, ItemOperation::Upsert] => ['PUT', $this->path('identity.me.profile', [])],
            default => throw new \LogicException('The envelope allowed an operation the dispatcher cannot map.'),
        };
    }

    /**
     * @param  array<string, string|null>  $parameters
     */
    private function path(string $routeName, array $parameters): string
    {
        return route($routeName, $parameters, false);
    }

    /**
     * A profile item is the caller's own profile or it is not found: a profile
     * has no id in its path, so the item's id is checked here (A-6).
     */
    private function assertOwnProfile(BatchItem $item, ?string $callerId): void
    {
        if ($item->type !== ItemType::Profile) {
            return;
        }

        if ($callerId === null) {
            throw new ApiRefusal(ErrorCode::NotFound);
        }

        try {
            $ownProfileId = $this->profiles->find($callerId)->id;
        } catch (ModelNotFoundException) {
            // A caller with no profile row owns no profile id: not found, never a 500.
            throw new ApiRefusal(ErrorCode::NotFound);
        }

        if ($ownProfileId !== $item->id) {
            throw new ApiRefusal(ErrorCode::NotFound);
        }
    }

    private function subRequest(Request $parent, string $method, string $path, BatchItem $item, string $locale): Request
    {
        $data = $item->data;

        if ($item->type === ItemType::Transaction && $item->operation === ItemOperation::Restore && ! array_key_exists('deleted_at', $data)) {
            $data['deleted_at'] = null;
        }

        $content = (string) json_encode($data === [] ? new \stdClass : $data);

        $server = array_intersect_key($parent->server->all(), array_flip(['REMOTE_ADDR', 'HTTPS', 'SERVER_NAME', 'SERVER_PORT', 'HTTP_HOST']));
        $sub = Request::create($path, $method, [], [], [], $server, $content);

        $sub->headers->set('Content-Type', 'application/json');
        $sub->headers->set('Accept', 'application/json');
        $sub->headers->set('Accept-Language', $locale);

        foreach (['X-Client-Platform', 'X-Client-Version', 'X-Correlation-Id'] as $header) {
            if ($parent->headers->has($header)) {
                $sub->headers->set($header, (string) $parent->headers->get($header));
            }
        }

        return $sub;
    }

    /**
     * Puts back the route the batch itself runs under: the container's route
     * binding, and the router's notion of the current route.
     */
    private function restoreRoute(?Route $parentRoute, ?Route $currentRoute): void
    {
        if ($parentRoute !== null) {
            app()->instance(Route::class, $parentRoute);
        }

        Closure::bind(function () use ($currentRoute): void {
            $this->current = $currentRoute;
        }, $this->router, Router::class)();
    }
}
