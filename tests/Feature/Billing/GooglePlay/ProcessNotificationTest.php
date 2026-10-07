<?php

use App\Modules\Billing\Application\GooglePlay\Jobs\ProcessPlayNotification;
use App\Modules\Billing\Application\GooglePlay\ReceivePlayNotification;
use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Infrastructure\Models\PlayNotification;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * US3: Google keeps the subscription in step (spec 024, FR-036 to FR-039).
 * A notification only names the purchase; the job re-reads Google, so any
 * order and any repetition converge on Google's current state.
 */

beforeEach(function () {
    $this->freezeTime();
    mapPlayProducts();
    Queue::fake();
    [$this->user, $this->credential] = playBuyer();
    $this->token = playToken('proc');
    scriptPlay($this->token, ['obfuscatedRef' => app(PlayAccountReferences::class)->for($this->user->id)]);
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])->assertOk();
    $this->subscription = Subscription::query()->sole();
});

function processPush(array $notification, ?string $messageId = null): PlayNotification
{
    app(ReceivePlayNotification::class)->receive(pubsubPush($notification, $messageId));
    $row = PlayNotification::query()->latest('received_at')->orderByDesc('id')->firstOrFail();
    runNotificationJob($row->id);

    return $row->fresh();
}

function runNotificationJob(string $id): void
{
    app()->call([new ProcessPlayNotification($id), 'handle']);
}

function entitlementAuditActions(): array
{
    return AuditEntry::query()->where('event', 'entitlement_changed')->orderBy('id')->get()->map(fn (AuditEntry $e) => $e->detail['action'])->all();
}

test('a renewal extends the period once, and the row ends processed with its token erased', function () {
    scriptPlay($this->token, ['expiresAt' => now()->addDays(60)->toDateTimeImmutable(), 'acknowledged' => true]);

    $row = processPush(subscriptionNotification($this->token, 2));

    expect($this->subscription->fresh()->current_period_end->equalTo(now()->addDays(60)))->toBeTrue()
        ->and(entitlementAuditActions())->toBe(['purchase', 'renew'])
        ->and($row->state)->toBe('processed')->and($row->token_sealed)->toBeNull()->and($row->attempts)->toBe(1);
});

test('entering grace uses Google\'s grace end and publishes one lapse', function () {
    Event::fake([SubscriptionLapsed::class, SubscriptionActivated::class]);
    scriptPlay($this->token, ['state' => PlayState::Grace, 'expiresAt' => now()->addDays(3)->toDateTimeImmutable(), 'graceUntil' => now()->addDays(3)->toDateTimeImmutable(), 'acknowledged' => true]);

    processPush(subscriptionNotification($this->token, 6));

    $fresh = $this->subscription->fresh();
    expect($fresh->status)->toBe('grace')->and($fresh->grace_until->equalTo(now()->addDays(3)))->toBeTrue();
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
});

test('a hold or a pause ends access through the zero-length grace with one lapse', function (int $type, PlayState $state) {
    Event::fake([SubscriptionLapsed::class, SubscriptionActivated::class]);
    scriptPlay($this->token, ['state' => $state, 'acknowledged' => true]);

    processPush(subscriptionNotification($this->token, $type));

    expect($this->subscription->fresh()->status)->toBe('expired')->and(entitlementAuditActions())->toBe(['purchase', 'expire']);
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
})->with([[5, PlayState::OnHold], [10, PlayState::Paused], [13, PlayState::Expired]]);

test('recovery from hold activates again with one activation', function () {
    scriptPlay($this->token, ['state' => PlayState::OnHold, 'acknowledged' => true]);
    processPush(subscriptionNotification($this->token, 5));
    Event::fake([SubscriptionLapsed::class, SubscriptionActivated::class]);
    scriptPlay($this->token, ['expiresAt' => now()->addDays(30)->toDateTimeImmutable(), 'acknowledged' => true]);

    processPush(subscriptionNotification($this->token, 1));

    expect($this->subscription->fresh()->status)->toBe('active')->and(entitlementAuditActions())->toBe(['purchase', 'expire', 'recover']);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

test('a canceled subscription keeps access with auto-renew off, then expires when its period ends', function () {
    scriptPlay($this->token, ['state' => PlayState::Canceled, 'autoRenew' => false, 'acknowledged' => true]);
    processPush(subscriptionNotification($this->token, 3));

    expect($this->subscription->fresh())->status->toBe('active')->auto_renew->toBeFalse();

    $this->travel(31)->days();
    scriptPlay($this->token, ['state' => PlayState::Expired, 'autoRenew' => false, 'acknowledged' => true]);
    processPush(subscriptionNotification($this->token, 13));

    expect($this->subscription->fresh()->status)->toBe('expired');
});

test('a voided purchase revokes with the refund reason, once, and nothing revives it', function () {
    Event::fake([SubscriptionLapsed::class, SubscriptionActivated::class]);

    $row = processPush(['voidedPurchaseNotification' => ['purchaseToken' => $this->token, 'orderId' => 'GPA.1', 'productType' => 1, 'refundType' => 1]]);
    scriptPlay($this->token, ['acknowledged' => true]);
    processPush(subscriptionNotification($this->token, 4)); // a late "purchased"/renewed for the same token

    expect($this->subscription->fresh()->status)->toBe('revoked')
        ->and($row->state)->toBe('processed')
        ->and(PlayPurchase::query()->sole()->play_state)->toBe('revoked')
        ->and(AuditEntry::query()->where('event', 'entitlement_changed')->orderBy('id')->get()->last()->detail)->toMatchArray(['action' => 'revoke', 'reason' => 'refunded']);
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
});

test('processing the same notification twice, or out of order, converges with no second audit entry', function () {
    scriptPlay($this->token, ['state' => PlayState::OnHold, 'acknowledged' => true]);

    $first = processPush(subscriptionNotification($this->token, 5), 'dup-1');
    runNotificationJob($first->id);                                   // the same row again
    processPush(subscriptionNotification($this->token, 4), 'dup-2');  // a late "purchased" while Google still says on hold
    processPush(subscriptionNotification($this->token, 5), 'dup-3');  // another hold

    expect($this->subscription->fresh()->status)->toBe('expired')
        ->and(entitlementAuditActions())->toBe(['purchase', 'expire']);
});

test('a notification for an unlinked purchase whose reference names an account links it and acknowledges it', function () {
    [$grace] = [person('grace@example.test')];
    $token = playToken('unlinked');
    scriptPlay($token, ['obfuscatedRef' => app(PlayAccountReferences::class)->for($grace->id)]);

    processPush(subscriptionNotification($token, 4));

    $purchase = PlayPurchase::query()->where('token_lookup', playLookup($token))->sole();
    expect($purchase->user_id)->toBe($grace->id)->and($purchase->acknowledged_at)->not->toBeNull()
        ->and(Subscription::query()->where('user_id', $grace->id)->sole()->status)->toBe('active')
        ->and(playGateway()->wasAcknowledged($token))->toBeTrue();
});

test('a notification for an unlinked purchase with no usable reference leaves it unlinked and unacknowledged', function () {
    $token = playToken('orphan');
    scriptPlay($token);

    $row = processPush(subscriptionNotification($token, 4));

    expect(PlayPurchase::query()->where('token_lookup', playLookup($token))->sole()->user_id)->toBeNull()
        ->and(playGateway()->wasAcknowledged($token))->toBeFalse()
        ->and($row->state)->toBe('processed');
});

test('a reference to an account that was purged is never linked', function () {
    $gone = person('gone@example.test');
    $reference = app(PlayAccountReferences::class)->for($gone->id);
    User::query()->whereKey($gone->id)->update(['state' => 'purged', 'purged_at' => now()]);
    $token = playToken('purged-ref');
    scriptPlay($token, ['obfuscatedRef' => $reference]);

    processPush(subscriptionNotification($token, 4));

    expect(PlayPurchase::query()->where('token_lookup', playLookup($token))->sole()->user_id)->toBeNull()
        ->and(Subscription::query()->where('user_id', $gone->id)->count())->toBe(0)
        ->and(playGateway()->wasAcknowledged($token))->toBeFalse();
});

test('a pending purchase is granted and acknowledged when Google later reports it paid, with no call from the app', function () {
    $token = playToken('slow');
    scriptPlay($token, ['state' => PlayState::Pending, 'expiresAt' => null]);
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $token, 'product_id' => 'plus_monthly'])->assertStatus(202);
    expect(Subscription::query()->count())->toBe(1);

    scriptPlay($token, ['state' => PlayState::Active]);
    processPush(subscriptionNotification($token, 4));

    expect(Subscription::query()->count())->toBe(2)
        ->and(Subscription::query()->latest('created_at')->first()->user_id)->toBe($this->user->id)
        ->and(playGateway()->wasAcknowledged($token))->toBeTrue();
});

test('while Google cannot be read the job fails to be retried: the row stays received', function () {
    scriptPlay($this->token, ['acknowledged' => true]);
    playGateway()->failNext();
    app(ReceivePlayNotification::class)->receive(pubsubPush(subscriptionNotification($this->token, 2), 'fail-1'));
    $id = (string) PlayNotification::query()->where('message_id', 'fail-1')->value('id');

    expect(fn () => runNotificationJob($id))->toThrow(RuntimeException::class);

    $row = PlayNotification::query()->find($id);
    expect($row->state)->toBe('received')->and($row->attempts)->toBe(1)->and($row->token_sealed)->not->toBeNull();

    runNotificationJob($id);
    expect(PlayNotification::query()->find($id)->state)->toBe('processed');
});

test('after its final failure the row is failed, its token erased, and one error is logged by id', function () {
    Log::spy();
    app(ReceivePlayNotification::class)->receive(pubsubPush(subscriptionNotification($this->token, 2), 'fail-2'));
    $id = (string) PlayNotification::query()->where('message_id', 'fail-2')->value('id');

    (new ProcessPlayNotification($id))->failed(new RuntimeException('Google Play could not be read yet.'));

    $row = PlayNotification::query()->find($id);
    expect($row->state)->toBe('failed')->and($row->token_sealed)->toBeNull();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'billing_play_notification_failed' && $context === ['notification_id' => $id])->once();
});

test('a product no plan maps is processed without a grant and without an acknowledgement', function () {
    Log::spy();
    $token = playToken('unmapped');
    scriptPlay($token, ['productId' => 'gold_forever', 'obfuscatedRef' => app(PlayAccountReferences::class)->for($this->user->id)]);

    $row = processPush(subscriptionNotification($token, 4, 'gold_forever'));

    expect($row->state)->toBe('processed')->and(PlayPurchase::query()->where('token_lookup', playLookup($token))->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(1)->and(playGateway()->wasAcknowledged($token))->toBeFalse();
});

test('a notification for a purchase whose account was purged has no effect on anyone', function () {
    User::query()->whereKey($this->user->id)->update(['state' => 'purged', 'purged_at' => now()]);
    scriptPlay($this->token, ['state' => PlayState::OnHold]);

    $row = processPush(subscriptionNotification($this->token, 5));

    expect($row->state)->toBe('ignored')->and($row->ignored_reason)->toBe('purged_account')
        ->and($this->subscription->fresh()->status)->toBe('active');
});

test('a terminal row is left alone by a repeated job', function () {
    scriptPlay($this->token, ['acknowledged' => true]);
    $row = processPush(subscriptionNotification($this->token, 2), 'again-1');

    runNotificationJob($row->id);

    expect(PlayNotification::query()->find($row->id)->attempts)->toBe(1);
});

test('the job carries the inbox id only, tries five times and backs off', function () {
    $job = new ProcessPlayNotification('0192f8d0-0000-7000-8000-0000000000cc');

    expect(get_object_vars($job))->not->toHaveKey('token')
        ->and($job->tries)->toBe(5)->and($job->backoff())->toBe([60, 300, 900, 3600])->and($job->uniqueId())->toBe('0192f8d0-0000-7000-8000-0000000000cc');
});
