<?php

namespace App\Modules\Notifications\Infrastructure\Push;

use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Delivery\PushDestination;
use App\Modules\Notifications\Domain\Delivery\PushGateway;
use App\Modules\Notifications\Domain\Messages\PushMessage;
use App\Modules\Notifications\Domain\Ports\AccessTokenSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The production adapter: FCM HTTP v1 through the framework's HTTP client,
 * authenticated by an access token from the service account (spec 023,
 * FR-039, ADR-0025). No SDK. A 401 forgets the cached token, mints another
 * and repeats the send once. The token, the key and the destination token are
 * never logged and never put in an exception message.
 */
final class FcmPushGateway implements PushGateway
{
    public function __construct(
        private readonly AccessTokenSource $tokens,
        private readonly string $projectId,
    ) {}

    public function delivers(): bool
    {
        return true;
    }

    public function send(PushMessage $message, PushDestination $destination): GatewayOutcome
    {
        try {
            $response = $this->post($message, $destination);

            if ($response->status() === 401) {
                $this->tokens->forget();
                $response = $this->post($message, $destination);
            }
        } catch (ConnectionException) {
            return FcmErrorMap::connectionFailed();
        } catch (RuntimeException) {
            // The service account could not get an access token (never echoed): a later attempt may.
            return GatewayOutcome::retryLater(null, 'TOKEN_UNAVAILABLE');
        }

        return FcmErrorMap::outcomeOf($response);
    }

    private function post(PushMessage $message, PushDestination $destination): Response
    {
        return $this->request()->post(
            "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
            self::body($message, $destination),
        );
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->tokens->token())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('notifications.push.fcm.timeout'))
            ->connectTimeout((int) config('notifications.push.fcm.connect_timeout'));
    }

    /**
     * @return array{message: array<string, mixed>}
     */
    public static function body(PushMessage $message, PushDestination $destination): array
    {
        return ['message' => [
            'token' => $destination->token,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => $message->data,
            'android' => [
                'collapse_key' => $message->collapseKey,
                'priority' => $message->priority->value,
                'ttl' => '86400s',
                'notification' => ['channel_id' => $message->channel, 'tag' => $message->collapseKey],
            ],
        ]];
    }
}
