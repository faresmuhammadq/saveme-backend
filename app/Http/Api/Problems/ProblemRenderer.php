<?php

namespace App\Http\Api\Problems;

use App\Http\Api\Locales;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every failure under /api as an RFC 9457 problem body
 * (docs/api-conventions.md, Errors): `application/problem+json`, the catalogue
 * title, a localised detail, the code, and the request id repeated from the
 * X-Request-Id header. Nothing else in the application builds a problem.
 *
 * An unexpected exception becomes INTERNAL_ERROR with no detail of its own,
 * so a stack trace never reaches a client.
 */
final class ProblemRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        $locale = in_array(app()->getLocale(), config('api.supported_locales'), true)
            ? app()->getLocale()
            : Locales::resolve($request);
        app()->setLocale($locale);

        [$code, $fieldErrors, $extensions, $retryAfter, $detailKey, $headers] = $this->classify($e);

        $requestId = Context::get('request_id') ?? (string) Str::uuid7();

        $body = [
            'type' => rtrim((string) config('api.problem_base_url'), '/').'/problems/'.Str::of($code->value)->lower()->replace('_', '-'),
            'title' => $code->title(),
            'status' => $code->status(),
            'detail' => __($detailKey ?? 'problems.'.$code->value),
            'code' => $code->value,
            'request_id' => $requestId,
            ...$extensions,
        ];

        if ($retryAfter !== null) {
            $body['retry_after_seconds'] = $retryAfter;
            $headers['Retry-After'] = (string) $retryAfter;

            if ($code === ErrorCode::RateLimited) {
                $headers += ['RateLimit-Remaining' => '0', 'RateLimit-Reset' => (string) $retryAfter];
            }
        }

        if ($fieldErrors !== []) {
            $body['errors'] = array_map(fn (FieldError $error): array => [
                'pointer' => $error->pointer,
                'code' => $error->code->value,
                'detail' => $error->detail ?? __('problems.fields.'.$error->code->value),
            ], $fieldErrors);
        }

        return new JsonResponse($body, $code->status(), [
            ...$headers,
            'Content-Type' => 'application/problem+json',
            'Content-Language' => $locale,
            'X-Request-Id' => $requestId,
        ]);
    }

    /**
     * @return array{ErrorCode, list<FieldError>, array<string, scalar|array<mixed>>, ?int, ?string, array<string, string>}
     */
    private function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof Refusal => [$e->errorCode, $e->fieldErrors, $e->extensions, $e->retryAfterSeconds, $e->detailKey, []],
            $e instanceof ValidationException => [ErrorCode::ValidationFailed, FieldErrors::fromValidator($e->validator), [], null, null, []],
            $e instanceof AuthenticationException => [ErrorCode::Unauthenticated, [], [], null, null, []],
            $e instanceof ThrottleRequestsException => $this->throttled($e),
            $e instanceof MethodNotAllowedHttpException => [ErrorCode::MethodNotAllowed, [], [], null, null, ['Allow' => (string) ($e->getHeaders()['Allow'] ?? '')]],
            $e instanceof NotFoundHttpException => [ErrorCode::NotFound, [], [], null, null, []],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 404 => [ErrorCode::NotFound, [], [], null, null, []],
            default => [ErrorCode::InternalError, [], [], null, null, []],
        };
    }

    /**
     * Laravel's throttle speaks X-RateLimit-*; the contract promises RateLimit-*.
     *
     * @return array{ErrorCode, list<FieldError>, array<string, scalar|array<mixed>>, ?int, ?string, array<string, string>}
     */
    private function throttled(ThrottleRequestsException $e): array
    {
        $given = $e->getHeaders();
        $retryAfter = (int) ($given['Retry-After'] ?? 60);

        return [ErrorCode::RateLimited, [], [], $retryAfter, null, array_filter([
            'RateLimit-Limit' => (string) ($given['X-RateLimit-Limit'] ?? ''),
        ])];
    }
}
