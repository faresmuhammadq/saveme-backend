<?php

use App\Modules\Billing\Application\Departure\EndSubscriptions;
use App\Modules\Billing\Application\GooglePlay\Jobs\ProcessPlayNotification;
use App\Modules\Billing\Application\GooglePlay\ReceivePlayNotification;
use App\Modules\Billing\Application\Subscriptions\PurgeEndedSubscriptions;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Infrastructure\Models\PlayAccountReference;
use App\Modules\Billing\Infrastructure\Models\PlayNotification;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Queue;

/*
 * Accounts that leave (spec 024, FR-048, FR-049; spec 022 FR-047): Google Play
 * is left alone, the reference and the sealed tokens go, the rows stay 400 days.
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    mapPlayProducts();
    [$this->user, $this->credential] = playBuyer();
    $this->token = playToken('purge');
    scriptPlay($this->token, ['obfuscatedRef' => app(PlayAccountReferences::class)->for($this->user->id)]);
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])->assertOk();
    $this->subscription = Subscription::query()->sole();
    $this->purchase = PlayPurchase::query()->sole();
});

test('the purge deletes the reference, erases the sealed tokens, stamps the rows and leaves the Google Play subscription alone', function () {
    $counts = app(EndSubscriptions::class)->purge($this->user->id, purgeContextFor($this->user->id));

    expect($counts->get('subscriptions_ended'))->toBe(0)
        ->and(PlayAccountReference::query()->count())->toBe(0)
        ->and($this->purchase->fresh()->token_sealed)->toBeNull()
        ->and($this->purchase->fresh()->ended_by_account_purge_at)->not->toBeNull()
        ->and($this->purchase->fresh()->user_id)->toBe($this->user->id)
        ->and($this->purchase->fresh()->token_lookup)->toBe(playLookup($this->token))
        ->and($this->subscription->fresh()->status)->toBe('active')
        ->and($this->subscription->fresh()->ended_by_account_purge_at)->not->toBeNull();
});

test('a second call changes nothing', function () {
    app(EndSubscriptions::class)->purge($this->user->id, purgeContextFor($this->user->id));
    $stamp = $this->purchase->fresh()->ended_by_account_purge_at;
    $this->travel(5)->minutes();

    app(EndSubscriptions::class)->purge($this->user->id, purgeContextFor($this->user->id));

    expect($this->purchase->fresh()->ended_by_account_purge_at->equalTo($stamp))->toBeTrue();
});

test('once the account is purged a notification for its purchase has no effect on anyone', function () {
    app(EndSubscriptions::class)->purge($this->user->id, purgeContextFor($this->user->id));
    User::query()->whereKey($this->user->id)->update(['state' => 'purged', 'purged_at' => now()]);
    scriptPlay($this->token, ['state' => PlayState::OnHold]);

    app(ReceivePlayNotification::class)->receive(pubsubPush(subscriptionNotification($this->token, 5), 'purged-1'));
    app()->call([new ProcessPlayNotification((string) PlayNotification::query()->value('id')), 'handle']);

    expect(PlayNotification::query()->sole())->state->toBe('ignored')->ignored_reason->toBe('purged_account')
        ->and($this->subscription->fresh()->status)->toBe('active');
});

test('another account presenting the purged account\'s token is 409', function () {
    app(EndSubscriptions::class)->purge($this->user->id, purgeContextFor($this->user->id));
    User::query()->whereKey($this->user->id)->update(['state' => 'purged', 'purged_at' => now()]);
    [, $other] = playBuyer('bob@example.test');

    playCall($other, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])
        ->assertStatus(409)->assertJsonPath('code', 'PURCHASE_ALREADY_LINKED');
});

test('the reconciliation passes skip a purchase whose token was erased', function () {
    app(EndSubscriptions::class)->purge($this->user->id, purgeContextFor($this->user->id));
    $this->travel(40)->days();
    $reads = playGateway()->reads;

    $this->artisan('billing:play-reconcile')->assertSuccessful();
    $this->artisan('billing:play-sweep')->assertSuccessful();

    expect(playGateway()->reads)->toBe($reads);
});

test('after 400 days the stamped purchase goes with its subscription, in that order', function () {
    app(EndSubscriptions::class)->purge($this->user->id, purgeContextFor($this->user->id));
    $this->travel(399)->days();
    app(PurgeEndedSubscriptions::class)->run();
    expect(PlayPurchase::query()->count())->toBe(1)->and(Subscription::query()->count())->toBe(1);

    $this->travel(2)->days();
    app(PurgeEndedSubscriptions::class)->run();

    expect(PlayPurchase::query()->count())->toBe(0)->and(Subscription::query()->count())->toBe(0);
});
