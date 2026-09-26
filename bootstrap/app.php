<?php

use App\Http\Api\ApiFallback;
use App\Http\Api\Middleware\AssignRequestId;
use App\Http\Api\Middleware\NegotiateJson;
use App\Http\Api\Middleware\RefuseInsecureTransport;
use App\Http\Api\Middleware\RequireClientVersion;
use App\Http\Api\Middleware\ResolveContentLanguage;
use App\Http\Api\Middleware\StandardRateLimitHeaders;
use App\Http\Api\Problems\ProblemRenderer;
use App\Modules\Shared\Domain\Errors\Refusal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Anything under /api/v1 that no module registered, for any
            // method, is a problem body — 404, or 405 with Allow when the path
            // exists for other methods (docs/api-conventions.md, Errors).
            Route::middleware('api')->prefix('api/v1')->group(function (): void {
                Route::any('{fallbackPlaceholder}', ApiFallback::class)->where('fallbackPlaceholder', '.*')->fallback();
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API runtime every /api/v1 route runs through (spec 007, R-15).
        // The request id comes first, so even a transport refusal carries it.
        $middleware->group('api', [
            AssignRequestId::class,
            RefuseInsecureTransport::class,
            NegotiateJson::class,
            RequireClientVersion::class,
            ResolveContentLanguage::class,
            StandardRateLimitHeaders::class,
            SubstituteBindings::class,
        ]);

        // Trusted proxies (for HTTPS detection behind a TLS terminator) are
        // configured by the feature that chooses the deployment target.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A refusal is an answer, not a fault; it is never reported.
        $exceptions->dontReport(Refusal::class);

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return app(ProblemRenderer::class)->render($e, $request);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
