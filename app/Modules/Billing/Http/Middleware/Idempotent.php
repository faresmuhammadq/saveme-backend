<?php

namespace App\Modules\Billing\Http\Middleware;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Billing\Domain\Idempotency\CanonicalJson;
use App\Modules\Billing\Domain\Idempotency\IdempotencyOutcome;
use App\Modules\Billing\Domain\Ports\IdempotencyStore;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `Idempotency-Key` for the operations that need it (docs/api-conventions.md,
 * Idempotency; spec 020, R-2). Registered as `billing.idempotent`; the
 * `anonymous` parameter pins the scope for a public operation, otherwise the
 * scope is the signed-in user (so `auth:api` runs first).
 *
 * Only a 2xx answer is stored. Any other answer, or an exception, releases
 * the key so a corrected request can use it again. A replay never reaches the
 * controller, so it spends no budget.
 */
final class Idempotent
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]{16,255}$/';

    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next, string $scope = 'user'): Response
    {
        $key = $request->headers->get('Idempotency-Key');

        if ($key === null || $key === '') {
            throw new ApiRefusal(ErrorCode::IdempotencyKeyRequired);
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiRefusal(ErrorCode::IdempotencyKeyInvalid);
        }

        $scopeName = $scope === 'anonymous' ? 'anonymous' : 'user:'.(string) $request->user()?->getAuthIdentifier();
        $fingerprint = CanonicalJson::fingerprint($request->method(), $request->getPathInfo(), $request->getContent());
        $outcome = $this->store->begin($scopeName, $key, $fingerprint, now()->toDateTimeImmutable());

        switch ($outcome->kind) {
            case IdempotencyOutcome::REUSED:
                throw new ApiRefusal(ErrorCode::IdempotencyKeyReused);
            case IdempotencyOutcome::IN_PROGRESS:
                throw new ApiRefusal(ErrorCode::IdempotencyRequestInProgress, retryAfterSeconds: 1);
            case IdempotencyOutcome::REPLAY:
                return new JsonResponse((string) $outcome->body, (int) $outcome->status, ['Idempotency-Replayed' => 'true'], json: true);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->store->abandon($scopeName, $key);

            throw $e;
        }

        $body = (string) $response->getContent();

        if ($response->isSuccessful() && is_array(json_decode($body, true))) {
            $this->store->complete($scopeName, $key, $response->getStatusCode(), $body);
        } else {
            $this->store->abandon($scopeName, $key);
        }

        return $response;
    }
}
