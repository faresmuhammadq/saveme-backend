<?php

use App\Modules\Billing\Application\GooglePlay\Jobs\AcknowledgePlayPurchase;
use App\Modules\Billing\Application\GooglePlay\ReconcilePlayPurchase;
use App\Modules\Billing\Domain\GooglePlay\GatewayOutcome;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\PlayTokenSealer;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * The server acknowledges, after the grant, never the client; a purchase no
 * account has linked is never acknowledged (spec 024, FR-013, FR-014, FR-039).
 */

beforeEach(function () {
    mapPlayProducts();
    [$this->user, $this->credential] = playBuyer();
    $this->token = playToken('ack');
});

function verifyToken(string $token): TestResponse
{
    return playCall(test()->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $token, 'product_id' => 'plus_monthly']);
}

test('a linked paid purchase is acknowledged once, after the subscription is recorded', function () {
    scriptPlay($this->token);

    verifyToken($this->token)->assertOk()->assertJsonPath('data.purchase.acknowledged', true);

    expect(playGateway()->acknowledged)->toBe([['kind' => 'subscription', 'product_id' => 'plus_monthly', 'token' => $this->token]])
        ->and(Subscription::query()->count())->toBe(1)
        ->and(PlayPurchase::query()->sole()->acknowledged_at)->not->toBeNull();
});

test('a failed acknowledgement keeps the grant, answers acknowledged false and queues a retry', function () {
    Queue::fake();
    scriptPlay($this->token);
    playGateway()->failNextAcknowledgements();

    $response = verifyToken($this->token)->assertOk();

    expect($response->json('data.purchase.acknowledged'))->toBeFalse()
        ->and($response->json('data.subscription.status'))->toBe('active')
        ->and(PlayPurchase::query()->sole()->acknowledged_at)->toBeNull();
    Queue::assertPushed(AcknowledgePlayPurchase::class, fn (AcknowledgePlayPurchase $job) => $job->purchaseId === PlayPurchase::query()->value('id') && $job->queue === 'billing');
});

test('the retry job acknowledges and records it, and a second run does nothing', function () {
    Queue::fake();
    scriptPlay($this->token);
    playGateway()->failNextAcknowledgements();
    verifyToken($this->token)->assertOk();
    $id = (string) PlayPurchase::query()->value('id');

    (new AcknowledgePlayPurchase($id))->handle(app(PlayPurchaseRepository::class), playGateway(), app(PlayTokenSealer::class));
    (new AcknowledgePlayPurchase($id))->handle(app(PlayPurchaseRepository::class), playGateway(), app(PlayTokenSealer::class));

    expect(PlayPurchase::query()->sole()->acknowledged_at)->not->toBeNull()
        ->and(playGateway()->acknowledged)->toHaveCount(1);
});

test('the retry job fails loudly while Google cannot acknowledge, so the queue retries it', function () {
    Queue::fake();
    scriptPlay($this->token);
    playGateway()->failNextAcknowledgements(2);
    verifyToken($this->token)->assertOk();

    expect(fn () => (new AcknowledgePlayPurchase((string) PlayPurchase::query()->value('id')))->handle(app(PlayPurchaseRepository::class), playGateway(), app(PlayTokenSealer::class)))
        ->toThrow(RuntimeException::class);
});

test('the job carries the purchase id only and retries five times with backoff', function () {
    $job = new AcknowledgePlayPurchase('0192f8d0-0000-7000-8000-0000000000aa');

    expect(array_keys(get_object_vars($job)))->not->toContain('token')
        ->and($job->tries)->toBe(5)->and($job->backoff())->toBe([60, 300, 900, 3600])->and($job->uniqueId())->toBe('0192f8d0-0000-7000-8000-0000000000aa')
        ->and(json_encode($job))->not->toContain('purchase_token');
});

test('Google already holding the acknowledgement is recorded without a call', function () {
    scriptPlay($this->token, ['acknowledged' => true]);

    $response = verifyToken($this->token)->assertOk();

    expect($response->json('data.purchase.acknowledged'))->toBeTrue()
        ->and(playGateway()->acknowledged)->toBe([])
        ->and(PlayPurchase::query()->sole()->acknowledged_at)->not->toBeNull();
});

test('a repeat 400 "already acknowledged" from Google counts as acknowledged', function () {
    // The fake reports AlreadyAcknowledged through the same outcome the google adapter maps a 400 to.
    expect(GatewayOutcome::alreadyAcknowledged()->isAcknowledgedAtGoogle())->toBeTrue();
});

test('a purchase no account has linked is never acknowledged', function () {
    scriptPlay($this->token); // no reference, and nobody asks: a notification cannot name an owner

    $outcome = app(ReconcilePlayPurchase::class)->reconcile($this->token, PlayProductKind::Subscription, null, null);

    expect($outcome->userId)->toBeNull()->and($outcome->isSynced())->toBeTrue()
        ->and(playGateway()->acknowledged)->toBe([])
        ->and(PlayPurchase::query()->sole()->user_id)->toBeNull()
        ->and(Subscription::query()->count())->toBe(0);
});

test('a purchase whose reference names an account is linked by a notification and then acknowledged', function () {
    scriptPlay($this->token, ['obfuscatedRef' => app(PlayAccountReferences::class)->for($this->user->id)]);

    app(ReconcilePlayPurchase::class)->reconcile($this->token, PlayProductKind::Subscription, null, null);

    expect(PlayPurchase::query()->sole()->user_id)->toBe($this->user->id)
        ->and(playGateway()->acknowledged)->toHaveCount(1)
        ->and(Subscription::query()->sole()->user_id)->toBe($this->user->id);
});

test('the job never acknowledges an unlinked or an unpaid purchase', function () {
    $unlinked = PlayPurchase::factory()->create(['play_state' => 'active', 'token_sealed' => app(PlayTokenSealer::class)->seal('x', 'play_purchase', '0192f8d0-0000-7000-8000-0000000000bb')]);
    $unpaid = PlayPurchase::factory()->linkedTo($this->user->id)->create(['play_state' => 'expired']);

    foreach ([$unlinked, $unpaid] as $purchase) {
        (new AcknowledgePlayPurchase($purchase->id))->handle(app(PlayPurchaseRepository::class), playGateway(), app(PlayTokenSealer::class));
    }

    expect(playGateway()->acknowledged)->toBe([]);
});

test('an expired token is never acknowledged', function () {
    scriptPlay($this->token, ['state' => PlayState::Expired, 'expiresAt' => now()->subDays(3)->toDateTimeImmutable()]);

    verifyToken($this->token)->assertOk();

    expect(playGateway()->acknowledged)->toBe([]);
});
