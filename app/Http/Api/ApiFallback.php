<?php

namespace App\Http\Api;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * What answers under /api/v1 when no route matched: 405 with Allow when the
 * path exists for other methods, otherwise 404 — always a problem body, for
 * every method (docs/api-conventions.md, Errors).
 */
final class ApiFallback
{
    public function __invoke(Request $request, Router $router): never
    {
        $allowed = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if ($route->isFallback || in_array($request->getMethod(), $route->methods(), true)) {
                continue;
            }

            $probe = Request::create($request->getPathInfo(), $route->methods()[0]);
            if ($route->matches($probe, includingMethod: false)) {
                $allowed = [...$allowed, ...$route->methods()];
            }
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException(array_values(array_unique($allowed)));
        }

        throw new ApiRefusal(ErrorCode::NotFound);
    }
}
