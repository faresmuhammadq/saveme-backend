<?php

use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\GooglePlay\VoidedPurchase;
use App\Modules\Billing\Infrastructure\Models\PlayNotification;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * US6: nothing is lost when a notification is (spec 024, FR-044 to FR-047,
 * FR-014, R-10). The hourly and daily passes bring the record to Google's
 * state without any notification, and never move a Play subscription by dates alone
 * until Google has been silent for 72 hours.
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    mapPlayProducts();
    [$this->user, $this->credential] = playBuyer();
    $this->token = playToken('rec');
    scriptPlay($this->token);
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])->assertOk();
    $this->subscription = Subscription::query()->sole();
});

function reconcileHourly(): void
{
    test()->artisan('billing:play-reconcile')->assertSuccessful();
    app()->forgetScopedInstances();
}

function sweepDaily(): void
{
    test()->artisan('billing:play-sweep')->assertSuccessful();
    app()->forgetScopedInstances();
}

test('a period that ended with no notification is renewed by the hourly pass: extended, no lapse', function () {
    $this->travel(31)->days();
    scriptPlay($this->token, ['expiresAt' => now()->addDays(29)->toDateTimeImmutable(), 'acknowledged' => true]);
    Event::fake([SubscriptionLapsed::class, SubscriptionActivated::class]);

    reconcileHourly();

    expect($this->subscription->fresh()->status)->toBe('active')->and($this->subscription->fresh()->current_period_end->equalTo(now()->addDays(29)))->toBeTrue();
    Event::assertNotDispatched(SubscriptionLapsed::class);
});

test('a period that ended and Google says expired is lapsed once, by Google\'s word', function () {
    $this->travel(31)->days();
    scriptPlay($this->token, ['state' => PlayState::Expired, 'expiresAt' => now()->subDay()->toDateTimeImmutable(), 'acknowledged' => true]);
    Event::fake([SubscriptionLapsed::class]);

    reconcileHourly();
    reconcileHourly();

    expect($this->subscription->fresh()->status)->toBe('expired');
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
});

test('it never reads what still has time left, and is safe to run twice', function () {
    $reads = playGateway()->reads;

    reconcileHourly();
    reconcileHourly();

    expect(playGateway()->reads)->toBe($reads)->and(AuditEntry::query()->where('event', 'entitlement_changed')->count())->toBe(1);
});

test('the date-driven lapse never touches it, however long ago it ended', function () {
    $this->travel(60)->days();

    test()->artisan('billing:lapse-subscriptions')->assertSuccessful();

    expect($this->subscription->fresh()->status)->toBe('active');
});

test('Google unreachable for under 72 hours changes nothing; after 72 hours of silence past the end it lapses by dates, with no grace', function () {
    $this->travel(31)->days();
    playGateway()->failNext(50);

    reconcileHourly();
    expect($this->subscription->fresh()->status)->toBe('active')->and(PlayPurchase::query()->sole()->unreachable_since)->not->toBeNull();

    $this->travel(71)->hours();
    reconcileHourly();
    expect($this->subscription->fresh()->status)->toBe('active');

    $this->travel(2)->hours();
    Event::fake([SubscriptionLapsed::class]);
    reconcileHourly();

    $fresh = $this->subscription->fresh();
    expect($fresh->status)->toBe('expired')->and($fresh->grace_until->lessThanOrEqualTo(now()))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'entitlement_changed')->get()->last()->detail['action'])->toBe('expire');
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
});

test('a successful read clears the unreachable mark', function () {
    $this->travel(31)->days();
    playGateway()->failNext(1);
    reconcileHourly();
    expect(PlayPurchase::query()->sole()->unreachable_since)->not->toBeNull();

    scriptPlay($this->token, ['expiresAt' => now()->addDays(10)->toDateTimeImmutable(), 'acknowledged' => true]);
    $this->travel(1)->hour();
    reconcileHourly();

    expect(PlayPurchase::query()->sole()->unreachable_since)->toBeNull()->and(PlayPurchase::query()->sole()->synced_at)->not->toBeNull();
});

test('Google unreachable for one purchase for 24 hours logs one warning by purchase id', function () {
    Log::spy();
    $this->travel(31)->days();
    playGateway()->failNext(50);
    reconcileHourly();
    $this->travel(24)->hours();

    reconcileHourly();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'billing_play_unreachable' && $context === ['purchase_id' => PlayPurchase::query()->value('id')])->once();
});

test('a linked purchase still unacknowledged is acknowledged by the hourly pass', function () {
    $token = playToken('late');
    scriptPlay($token);
    playGateway()->failNextAcknowledgements();
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $token, 'product_id' => 'plus_monthly'])->assertOk()->assertJsonPath('data.purchase.acknowledged', false);
    expect(PlayPurchase::query()->where('token_lookup', playLookup($token))->sole()->acknowledged_at)->toBeNull();

    reconcileHourly();

    expect(PlayPurchase::query()->where('token_lookup', playLookup($token))->sole()->acknowledged_at)->not->toBeNull()->and(playGateway()->wasAcknowledged($token))->toBeTrue();
});

test('a purchase still unacknowledged 48 hours after it was made is logged as an operator alert', function () {
    Log::spy();
    $token = playToken('overdue');
    scriptPlay($token);
    playGateway()->failNextAcknowledgements(100);
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $token, 'product_id' => 'plus_monthly'])->assertOk();
    PlayPurchase::query()->where('token_lookup', playLookup($token))->update(['purchased_at' => now()->subHours(49)]);

    reconcileHourly();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'billing_play_ack_overdue' && $context === ['count' => 1])->once();
});

test('a pending purchase younger than 3 days is re-read and granted when it turns paid; an older one is not', function () {
    $young = playToken('young');
    $old = playToken('old');
    foreach ([$young, $old] as $token) {
        scriptPlay($token, ['state' => PlayState::Pending, 'expiresAt' => null]);
        playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $token, 'product_id' => 'plus_monthly'])->assertStatus(202);
    }
    PlayPurchase::query()->where('token_lookup', playLookup($old))->update(['purchased_at' => now()->subDays(4)]);
    scriptPlay($young);
    scriptPlay($old);

    reconcileHourly();

    expect(Subscription::query()->count())->toBe(2)->and(playGateway()->wasAcknowledged($young))->toBeTrue()->and(playGateway()->wasAcknowledged($old))->toBeFalse();
});

test('the daily pass re-reads a granting subscription not read for 7 days', function () {
    PlayPurchase::query()->update(['synced_at' => now()->subDays(8)]);
    scriptPlay($this->token, ['expiresAt' => now()->addDays(45)->toDateTimeImmutable(), 'acknowledged' => true]);

    sweepDaily();

    expect($this->subscription->fresh()->current_period_end->equalTo(now()->addDays(45)))->toBeTrue();
});

test('the daily pass revokes a purchase Google lists as voided, once', function () {
    playGateway()->scriptVoided([new VoidedPurchase($this->token, 'refunded'), new VoidedPurchase(playToken('stranger'), 'refunded')]);
    Event::fake([SubscriptionLapsed::class]);

    sweepDaily();
    sweepDaily();

    expect($this->subscription->fresh()->status)->toBe('revoked')->and(PlayPurchase::query()->sole()->play_state)->toBe('revoked')
        ->and(AuditEntry::query()->where('event', 'entitlement_changed')->get()->last()->detail)->toMatchArray(['action' => 'revoke', 'reason' => 'refunded']);
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
    expect(Cache::get('billing:play:voided-watermark'))->toBe(now()->getTimestamp());
});

test('an unreachable voided list changes nothing and leaves the watermark alone', function () {
    playGateway()->scriptVoided([new VoidedPurchase($this->token, 'refunded')]);
    playGateway()->failNext(1); // consumed by the first read, so make the stale pass see nothing to read
    PlayPurchase::query()->update(['synced_at' => now()]);

    sweepDaily();

    expect($this->subscription->fresh()->status)->toBe('active')->and(Cache::get('billing:play:voided-watermark'))->toBeNull();
});

test('retention: unlinked purchases are deleted after 10 days, inbox rows after 30', function () {
    $stale = PlayPurchase::factory()->create(['purchased_at' => now()->subDays(11)]);
    $recent = PlayPurchase::factory()->create(['purchased_at' => now()->subDays(9)]);
    $linked = PlayPurchase::factory()->linkedTo($this->user->id)->create(['purchased_at' => now()->subDays(40)]);
    foreach ([['old', 31], ['new', 29]] as [$id, $days]) {
        DB::table('play_notifications')->insert(['id' => (string) Str::uuid7(), 'message_id' => $id, 'kind' => 'test', 'received_at' => now()->subDays($days), 'state' => 'ignored', 'ignored_reason' => 'test', 'attempts' => 0, 'updated_at' => now()]);
    }

    sweepDaily();

    expect(PlayPurchase::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(PlayPurchase::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(PlayPurchase::query()->whereKey($linked->id)->exists())->toBeTrue()
        ->and(PlayNotification::query()->pluck('message_id')->all())->toBe(['new']);
});

test('the passes log counts only', function () {
    Log::spy();

    reconcileHourly();
    sweepDaily();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'billing_play_reconciled' && ! str_contains(json_encode($context), $this->token))->twice();
});
