<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\GatewayOutcome;
use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayPurchaseRecord;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\GooglePlay\VoidedPage;
use App\Modules\Billing\Domain\GooglePlay\VoidedPurchase;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Play Developer API v3 through the framework HTTP client (spec 024,
 * R-1, ADR-0026): subscriptionsv2.get, subscriptions:acknowledge,
 * products.get, products:acknowledge and voidedpurchases.list, authorised by
 * a service-account access token. Purchase tokens travel in URL paths, so a
 * URL, a request or an exception message is never logged: only a status and
 * Google's own error reason.
 */
final class GooglePlayGateway implements PlayBillingGateway
{
    private const BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications';

    public function __construct(
        private readonly ServiceAccountTokenSource $tokens,
        private readonly string $packageName,
    ) {}

    public function readSubscription(#[\SensitiveParameter] string $token): GatewayOutcome
    {
        $response = $this->send('get', $this->url('purchases/subscriptionsv2/tokens/'.rawurlencode($token)));

        if ($response instanceof GatewayOutcome) {
            return $response;
        }

        $record = $response->successful() ? self::subscriptionRecord((array) $response->json()) : null;

        return $record !== null ? GatewayOutcome::found($record) : self::failure($response, readCall: true);
    }

    public function readProduct(string $productId, #[\SensitiveParameter] string $token): GatewayOutcome
    {
        $response = $this->send('get', $this->url('purchases/products/'.rawurlencode($productId).'/tokens/'.rawurlencode($token)));

        if ($response instanceof GatewayOutcome) {
            return $response;
        }

        $record = $response->successful() ? self::productRecord($productId, (array) $response->json()) : null;

        return $record !== null ? GatewayOutcome::found($record) : self::failure($response, readCall: true);
    }

    public function acknowledge(PlayProductKind $kind, string $productId, #[\SensitiveParameter] string $token): GatewayOutcome
    {
        $path = $kind === PlayProductKind::Subscription
            ? 'purchases/subscriptions/'.rawurlencode($productId).'/tokens/'.rawurlencode($token).':acknowledge'
            : 'purchases/products/'.rawurlencode($productId).'/tokens/'.rawurlencode($token).':acknowledge';

        $response = $this->send('post', $this->url($path), (object) []);

        if ($response instanceof GatewayOutcome) {
            return $response;
        }

        if ($response->successful()) {
            return GatewayOutcome::acknowledged();
        }

        // Google answers 400 invalidPurchaseState / "already acknowledged" for a repeat.
        if ($response->status() === 400 && in_array(self::reason($response), ['invalidPurchaseState', 'alreadyAcknowledged'], true)) {
            return GatewayOutcome::alreadyAcknowledged();
        }

        return self::failure($response, readCall: false);
    }

    public function voidedSince(DateTimeImmutable $since, ?string $pageToken): VoidedPage
    {
        $query = ['startTime' => (string) ($since->getTimestamp() * 1000), 'type' => 1, 'maxResults' => 1000];

        if ($pageToken !== null) {
            $query['token'] = $pageToken;
        }

        $response = $this->send('get', $this->url('purchases/voidedpurchases').'?'.http_build_query($query));

        if ($response instanceof GatewayOutcome || ! $response->successful()) {
            if (! $response instanceof GatewayOutcome) {
                self::logRefusal($response);
            }

            return VoidedPage::unavailable();
        }

        $items = [];

        foreach ((array) $response->json('voidedPurchases', []) as $voided) {
            if (is_array($voided) && is_string($voided['purchaseToken'] ?? null) && $voided['purchaseToken'] !== '') {
                $items[] = new VoidedPurchase($voided['purchaseToken'], ($voided['voidedSource'] ?? 0) === 2 ? 'revoked_by_provider' : 'refunded');
            }
        }

        $next = $response->json('tokenPagination.nextPageToken');

        return new VoidedPage($items, is_string($next) && $next !== '' ? $next : null);
    }

    private function url(string $path): string
    {
        return self::BASE.'/'.rawurlencode($this->packageName).'/'.$path;
    }

    /**
     * One call, with a single token refresh on a 401. A transport fault, a
     * 429 or a 5xx comes back as `Unavailable` (with Retry-After when given).
     */
    private function send(string $method, string $url, mixed $body = null): Response|GatewayOutcome
    {
        try {
            $response = $this->attempt($method, $url, $body);

            if ($response->status() === 401) {
                $this->tokens->forget();
                $response = $this->attempt($method, $url, $body);
            }
        } catch (ConnectionException) {
            return GatewayOutcome::unavailable();
        } catch (Throwable) {
            Log::warning('billing_play_gateway_fault');

            return GatewayOutcome::unavailable();
        }

        if ($response->status() === 429 || $response->status() >= 500 || $response->status() === 401) {
            return GatewayOutcome::unavailable(self::retryAfter($response));
        }

        if ($response->status() === 403) {
            Log::error('billing_play_gateway_forbidden', ['reason' => self::reason($response)]);

            return GatewayOutcome::unavailable(3600);
        }

        return $response;
    }

    private function attempt(string $method, string $url, mixed $body): Response
    {
        $request = $this->request();

        return $method === 'post' ? $request->post($url, $body) : $request->get($url);
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->tokens->token())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('billing.google_play.timeout'))
            ->connectTimeout((int) config('billing.google_play.connect_timeout'));
    }

    private static function failure(Response $response, bool $readCall): GatewayOutcome
    {
        $status = $response->status();

        if ($response->successful()) {
            // A 2xx that is not a purchase we can read: Google changed shape, or the token is not a purchase.
            Log::warning('billing_play_gateway_unreadable');

            return GatewayOutcome::unavailable();
        }

        if (in_array($status, [400, 404, 410], true)) {
            return GatewayOutcome::notFound();
        }

        self::logRefusal($response);

        return GatewayOutcome::unavailable();
    }

    private static function logRefusal(Response $response): void
    {
        Log::warning('billing_play_gateway_refused', ['status' => $response->status(), 'reason' => self::reason($response)]);
    }

    private static function reason(Response $response): string
    {
        $reason = $response->json('error.errors.0.reason') ?? $response->json('error.status');

        return is_string($reason) ? $reason : '';
    }

    private static function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : 60;
    }

    /**
     * @param  array<mixed>  $body  SubscriptionPurchaseV2
     */
    private static function subscriptionRecord(array $body): ?PlayPurchaseRecord
    {
        $state = match ($body['subscriptionState'] ?? null) {
            'SUBSCRIPTION_STATE_ACTIVE' => PlayState::Active,
            'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => PlayState::Grace,
            'SUBSCRIPTION_STATE_ON_HOLD' => PlayState::OnHold,
            'SUBSCRIPTION_STATE_PAUSED' => PlayState::Paused,
            'SUBSCRIPTION_STATE_CANCELED' => PlayState::Canceled,
            'SUBSCRIPTION_STATE_EXPIRED' => PlayState::Expired,
            'SUBSCRIPTION_STATE_PENDING' => PlayState::Pending,
            'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED' => PlayState::PendingCanceled,
            default => null,
        };

        $line = self::latestLineItem($body['lineItems'] ?? null);

        if ($state === null || $line === null || ! is_string($line['productId'] ?? null)) {
            return null;
        }

        $expires = self::instant($line['expiryTime'] ?? null);
        $linked = $body['linkedPurchaseToken'] ?? null;
        $reference = $body['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null;
        $basePlan = $line['offerDetails']['basePlanId'] ?? null;

        return new PlayPurchaseRecord(
            productId: $line['productId'],
            kind: PlayProductKind::Subscription,
            state: $state,
            expiresAt: $expires,
            graceUntil: $state === PlayState::Grace ? $expires : null,
            purchasedAt: self::instant($body['startTime'] ?? null) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            autoRenew: ($line['autoRenewingPlan']['autoRenewEnabled'] ?? false) === true,
            acknowledged: ($body['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            obfuscatedRef: is_string($reference) && $reference !== '' ? $reference : null,
            linkedToken: is_string($linked) && $linked !== '' ? $linked : null,
            isTest: isset($body['testPurchase']),
            basePlanId: is_string($basePlan) && $basePlan !== '' ? $basePlan : null,
        );
    }

    /**
     * @param  array<mixed>  $body  ProductPurchase
     */
    private static function productRecord(string $productId, array $body): ?PlayPurchaseRecord
    {
        $state = match ($body['purchaseState'] ?? null) {
            0 => PlayState::Active,
            1 => PlayState::PendingCanceled,
            2 => PlayState::Pending,
            default => null,
        };

        if ($state === null) {
            return null;
        }

        $millis = $body['purchaseTimeMillis'] ?? null;
        $reference = $body['obfuscatedExternalAccountId'] ?? null;

        return new PlayPurchaseRecord(
            productId: $productId,
            kind: PlayProductKind::OneTime,
            state: $state,
            expiresAt: null,
            graceUntil: null,
            purchasedAt: is_numeric($millis) ? self::fromMillis((int) $millis) : new DateTimeImmutable('now', new DateTimeZone('UTC')),
            autoRenew: false,
            acknowledged: ($body['acknowledgementState'] ?? 0) === 1,
            obfuscatedRef: is_string($reference) && $reference !== '' ? $reference : null,
            linkedToken: null,
            isTest: ($body['purchaseType'] ?? null) === 0,
            basePlanId: null,
        );
    }

    /**
     * The line item with the latest expiry.
     *
     * @return array<string, mixed>|null
     */
    private static function latestLineItem(mixed $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $best = null;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($best === null || (string) ($item['expiryTime'] ?? '') > (string) ($best['expiryTime'] ?? '')) {
                $best = $item;
            }
        }

        return $best;
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function fromMillis(int $millis): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.intdiv($millis, 1000)))->setTimezone(new DateTimeZone('UTC'));
    }
}
