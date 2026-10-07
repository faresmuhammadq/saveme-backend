<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Billing\Application\GooglePlay\PlayLog;
use App\Modules\Billing\Application\GooglePlay\ReceivePlayNotification;
use App\Modules\Billing\Infrastructure\GooglePlay\CertificatesUnavailable;
use App\Modules\Billing\Infrastructure\GooglePlay\PushTokenVerifier;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * POST /webhooks/google-play (spec 024, US4): Google's Cloud Pub/Sub push.
 * Authorised only by its OIDC token; nothing is written before it verifies,
 * and a refusal writes no database row (FR-035), only an hourly counter.
 */
final class PlayWebhookController
{
    private const MAX_BYTES = 65536;

    public function __invoke(Request $request, PushTokenVerifier $verifier, ReceivePlayNotification $receive): Response
    {
        $push = self::shape($request);

        try {
            $failure = $verifier->failure($request->bearerToken());
        } catch (CertificatesUnavailable) {
            throw new ApiRefusal(ErrorCode::ServiceUnavailable, retryAfterSeconds: 60);
        }

        if ($failure !== null) {
            $key = 'billing:play:rejected:'.$failure.':'.now()->utc()->format('YmdH');
            Cache::add($key, 0, now()->addHours(25));
            Cache::increment($key);
            PlayLog::pushRejected($failure);

            throw new ApiRefusal(ErrorCode::ProviderSignatureInvalid);
        }

        $receive->receive($push);

        return Envelope::noContent();
    }

    /**
     * @return array{message: array{data: string, messageId: string, publishTime?: string}, subscription: string}
     */
    private static function shape(Request $request): array
    {
        $raw = $request->getContent();
        $body = strlen($raw) > self::MAX_BYTES ? null : json_decode($raw, true);
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        if (! is_array($body) || ! is_array($message)
            || ! is_string($message['data'] ?? null) || ! is_string($message['messageId'] ?? null)
            || $message['messageId'] === '' || strlen($message['messageId']) > 128
            || ! is_string($body['subscription'] ?? null) || strlen($body['subscription']) > 255) {
            throw new ApiRefusal(ErrorCode::MalformedJson);
        }

        return [
            'message' => [
                'data' => $message['data'],
                'messageId' => $message['messageId'],
                ...(is_string($message['publishTime'] ?? null) ? ['publishTime' => $message['publishTime']] : []),
            ],
            'subscription' => $body['subscription'],
        ];
    }
}
