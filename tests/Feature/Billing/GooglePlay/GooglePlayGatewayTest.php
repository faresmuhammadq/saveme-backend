<?php

use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Infrastructure\GooglePlay\GooglePlayGateway;
use App\Modules\Billing\Infrastructure\GooglePlay\ServiceAccountKey;
use App\Modules\Billing\Infrastructure\GooglePlay\ServiceAccountTokenSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * The google adapter over the framework HTTP client (spec 024, R-1, FR-059):
 * token mint cached once, the five androidpublisher calls, the outcome
 * mapping. Never a real call: Http::fake throughout.
 */

const PUBLISHER = 'androidpublisher.googleapis.com/androidpublisher/v3/applications/com.example.app/*';
const TOKEN_URI = 'https://oauth2.googleapis.com/token';

function serviceAccountJson(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return base64_encode(json_encode(['client_email' => 'svc@example-project.iam.gserviceaccount.com', 'private_key_id' => 'kid-1', 'private_key' => $pem, 'token_uri' => TOKEN_URI]));
}

function liveGateway(): GooglePlayGateway
{
    return new GooglePlayGateway(new ServiceAccountTokenSource(ServiceAccountKey::from(serviceAccountJson(), TOKEN_URI)), 'com.example.app');
}

function playTokenEndpoint(int $expiresIn = 3600): array
{
    return [TOKEN_URI => Http::response(['access_token' => 'ya29.test-access', 'expires_in' => $expiresIn, 'token_type' => 'Bearer'])];
}

function subscriptionV2(array $overrides = []): array
{
    return [
        'kind' => 'androidpublisher#subscriptionPurchaseV2',
        'startTime' => '2026-10-06T09:12:44.000Z',
        'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
        'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
        'latestOrderId' => 'GPA.3333-4444-5555-66666',
        'lineItems' => [[
            'productId' => 'plus_monthly',
            'expiryTime' => '2026-11-06T09:12:44.000Z',
            'autoRenewingPlan' => ['autoRenewEnabled' => true],
            'offerDetails' => ['basePlanId' => 'monthly'],
        ]],
        'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => str_repeat('ab', 32)],
        ...$overrides,
    ];
}

test('a read mints one access token, caches it, and returns the purchase as a domain record', function () {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response(subscriptionV2())]);
    $gateway = liveGateway();

    $first = $gateway->readSubscription('tok/with+odd chars');
    $second = $gateway->readSubscription('another');

    $record = $first->record;
    expect($first->isFound())->toBeTrue()
        ->and($record->productId)->toBe('plus_monthly')
        ->and($record->state)->toBe(PlayState::Active)
        ->and($record->kind)->toBe(PlayProductKind::Subscription)
        ->and($record->expiresAt->format('c'))->toBe('2026-11-06T09:12:44+00:00')
        ->and($record->purchasedAt->format('c'))->toBe('2026-10-06T09:12:44+00:00')
        ->and($record->autoRenew)->toBeTrue()->and($record->acknowledged)->toBeFalse()->and($record->isTest)->toBeFalse()
        ->and($record->obfuscatedRef)->toBe(str_repeat('ab', 32))->and($record->basePlanId)->toBe('monthly')
        ->and($second->isFound())->toBeTrue();

    Http::assertSentCount(3); // one mint, two reads
    Http::assertSent(fn (Request $r) => $r->url() === TOKEN_URI && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count($r['assertion'], '.') === 2);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/purchases/subscriptionsv2/tokens/tok%2Fwith%2Bodd%20chars') && $r->hasHeader('Authorization', 'Bearer ya29.test-access'));
});

test('every Google state maps onto a stored state, a license tester is flagged, and the grace end is the expiry', function (string $google, PlayState $state) {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response(subscriptionV2(['subscriptionState' => $google, 'testPurchase' => (object) [], 'linkedPurchaseToken' => 'older-token', 'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED']))]);

    $record = liveGateway()->readSubscription('t')->record;

    expect($record->state)->toBe($state)->and($record->isTest)->toBeTrue()->and($record->linkedToken)->toBe('older-token')->and($record->acknowledged)->toBeTrue()
        ->and($record->graceUntil !== null)->toBe($state === PlayState::Grace);
})->with([
    ['SUBSCRIPTION_STATE_ACTIVE', PlayState::Active],
    ['SUBSCRIPTION_STATE_IN_GRACE_PERIOD', PlayState::Grace],
    ['SUBSCRIPTION_STATE_ON_HOLD', PlayState::OnHold],
    ['SUBSCRIPTION_STATE_PAUSED', PlayState::Paused],
    ['SUBSCRIPTION_STATE_CANCELED', PlayState::Canceled],
    ['SUBSCRIPTION_STATE_EXPIRED', PlayState::Expired],
    ['SUBSCRIPTION_STATE_PENDING', PlayState::Pending],
    ['SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED', PlayState::PendingCanceled],
]);

test('a one-time product read maps its purchase state, type and acknowledgement', function () {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response(['purchaseState' => 0, 'acknowledgementState' => 1, 'purchaseType' => 0, 'purchaseTimeMillis' => '1791277964000', 'obfuscatedExternalAccountId' => 'ref'])]);

    $record = liveGateway()->readProduct('plus_lifetime', 'tok')->record;

    expect($record->kind)->toBe(PlayProductKind::OneTime)->and($record->state)->toBe(PlayState::Active)->and($record->expiresAt)->toBeNull()
        ->and($record->acknowledged)->toBeTrue()->and($record->isTest)->toBeTrue()->and($record->obfuscatedRef)->toBe('ref')->and($record->productId)->toBe('plus_lifetime');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/purchases/products/plus_lifetime/tokens/tok'));
});

test('acknowledging posts to the right endpoint for each kind, and repeats are already acknowledged', function () {
    Http::fake([...playTokenEndpoint(), 'androidpublisher.googleapis.com/*:acknowledge' => Http::sequence()
        ->push([], 200)
        ->push(['error' => ['code' => 400, 'status' => 'FAILED_PRECONDITION', 'errors' => [['reason' => 'invalidPurchaseState']]]], 400)
        ->push([], 200)]);
    $gateway = liveGateway();

    expect($gateway->acknowledge(PlayProductKind::Subscription, 'plus_monthly', 'tok')->kind)->toBe('acknowledged')
        ->and($gateway->acknowledge(PlayProductKind::Subscription, 'plus_monthly', 'tok')->kind)->toBe('already_acknowledged')
        ->and($gateway->acknowledge(PlayProductKind::OneTime, 'plus_lifetime', 'tok2')->isAcknowledgedAtGoogle())->toBeTrue();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->body() === '{}' && str_contains($r->url(), '/purchases/subscriptions/plus_monthly/tokens/tok:acknowledge'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/purchases/products/plus_lifetime/tokens/tok2:acknowledge'));
});

test('Google not knowing a token is not found', function (int $status) {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response(['error' => ['code' => $status, 'status' => 'NOT_FOUND']], $status)]);

    expect(liveGateway()->readSubscription('unknown')->isNotFound())->toBeTrue();
})->with([400, 404, 410]);

test('a server error, a throttle or a dropped connection is unavailable, with Retry-After when Google gives one', function () {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::sequence()
        ->push('', 503)
        ->push('', 429, ['Retry-After' => '120'])
        ->pushFailedConnection()]);
    $gateway = liveGateway();

    $a = $gateway->readSubscription('t');
    $b = $gateway->readSubscription('t');
    $c = $gateway->readSubscription('t');

    expect($a->isUnavailable())->toBeTrue()->and($a->retryAfterSeconds)->toBe(60)
        ->and($b->isUnavailable())->toBeTrue()->and($b->retryAfterSeconds)->toBe(120)
        ->and($c->isUnavailable())->toBeTrue();
});

test('a 401 forgets the access token, mints once more and repeats the call once', function () {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::sequence()->push('', 401)->push(subscriptionV2())]);

    expect(liveGateway()->readSubscription('t')->isFound())->toBeTrue();

    Http::assertSentCount(4); // mint, read (401), mint, read
});

test('a second 401 is unavailable, not an endless loop', function () {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response('', 401)]);

    expect(liveGateway()->readSubscription('t')->isUnavailable())->toBeTrue();
});

test('a 403 is unavailable and logs the reason, never a URL or a token', function () {
    Log::spy();
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]], 403)]);

    expect(liveGateway()->readSubscription('secret-token-value')->isUnavailable())->toBeTrue();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'billing_play_gateway_forbidden' && ! str_contains(json_encode($context), 'secret-token-value'))->once();
});

test('an unreachable token endpoint is unavailable', function () {
    Http::fake([TOKEN_URI => Http::response('', 500), PUBLISHER => Http::response(subscriptionV2())]);

    expect(liveGateway()->readSubscription('t')->isUnavailable())->toBeTrue();
});

test('the voided purchases list pages and reads each token and its source', function () {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response(['voidedPurchases' => [['purchaseToken' => 'v1', 'voidedSource' => 0], ['purchaseToken' => 'v2', 'voidedSource' => 2]], 'tokenPagination' => ['nextPageToken' => 'page-2']])]);

    $page = liveGateway()->voidedSince(new DateTimeImmutable('2026-09-01 00:00:00 UTC'), null);

    expect($page->available)->toBeTrue()->and($page->nextPageToken)->toBe('page-2')
        ->and(array_map(fn ($i) => [$i->purchaseToken, $i->reason], $page->items))->toBe([['v1', 'refunded'], ['v2', 'revoked_by_provider']]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/purchases/voidedpurchases?') && str_contains($r->url(), 'startTime=1788220800000') && str_contains($r->url(), 'type=1'));
});

test('an unavailable Google is an unavailable voided page', function () {
    Http::fake([...playTokenEndpoint(), PUBLISHER => Http::response('', 500)]);

    expect(liveGateway()->voidedSince(new DateTimeImmutable, null)->available)->toBeFalse();
});
