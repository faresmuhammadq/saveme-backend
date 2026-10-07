<?php

use App\Modules\Billing\Application\GooglePlay\ApplyPlayState;
use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * What one Google read does to the subscription: the right audit entry and
 * event once, nothing on a repeat, one lapse for the zero-length grace hop
 * (spec 024, FR-020 to FR-026, FR-051).
 */

beforeEach(function () {
    $this->freezeTime();
    mapPlayProducts();
    $this->userId = (string) Str::uuid7();
    $this->plan = app(PlanRepository::class)->find(planIdByCode('plus_monthly'));
    $this->purchase = PlayPurchase::factory()->linkedTo($this->userId)->create();
    Event::fake([SubscriptionActivated::class, SubscriptionLapsed::class]);
});

function applyRead(array $record = []): ?string
{
    $purchase = app(PlayPurchaseRepository::class)->find(test()->purchase->id);

    return DB::transaction(fn () => app(ApplyPlayState::class)->apply($purchase, playRecord($record), test()->plan, test()->userId, now()->toDateTimeImmutable()));
}

function auditActions(): array
{
    return AuditEntry::query()->orderBy('id')->get()->map(fn (AuditEntry $e) => $e->detail['action'] ?? null)->all();
}

test('a first paid purchase creates the subscription backed by the purchase, with one audit entry and one activation', function () {
    $id = applyRead();

    $subscription = Subscription::query()->sole();
    expect($subscription->id)->toBe($id)
        ->and($subscription->provider)->toBe('google_play')->and($subscription->provider_ref)->toBe($this->purchase->id)
        ->and($subscription->status)->toBe('active')->and($subscription->auto_renew)->toBeTrue()
        ->and($subscription->current_period_end->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($subscription->source_meta)->toBe(['test' => false, 'base_plan_id' => 'monthly'])
        ->and($this->purchase->fresh()->subscription_id)->toBe($id)
        ->and(auditActions())->toBe(['purchase']);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    Event::assertNotDispatched(SubscriptionLapsed::class);
});

test('the audit entry names the subscription and plan, actor system, never a token or an amount', function () {
    applyRead();

    $entry = AuditEntry::query()->sole();
    expect($entry->actor_type)->toBe('system')->and($entry->subject_id)->toBe(Subscription::query()->value('id'))
        ->and($entry->detail)->toBe(['action' => 'purchase', 'plan_id' => $this->plan->id]);
});

test('a repeat of the same read writes nothing and publishes nothing', function () {
    applyRead();
    Event::fake([SubscriptionActivated::class, SubscriptionLapsed::class]);

    applyRead();
    applyRead();

    expect(AuditEntry::query()->count())->toBe(1)->and(Subscription::query()->count())->toBe(1);
    Event::assertNothingDispatched();
});

test('a renewal extends the period with a renew entry and no event', function () {
    applyRead();
    Event::fake([SubscriptionActivated::class, SubscriptionLapsed::class]);
    $first = Subscription::query()->sole();

    applyRead(['expiresAt' => now()->addDays(60)->toDateTimeImmutable()]);

    $renewed = $first->fresh();
    expect($renewed->current_period_end->equalTo(now()->addDays(60)))->toBeTrue()
        ->and($renewed->current_period_start->equalTo($first->current_period_end))->toBeTrue()
        ->and(auditActions())->toBe(['purchase', 'renew']);
    Event::assertNothingDispatched();
});

test('entering grace uses Google\'s grace end and publishes one lapse', function () {
    applyRead();

    applyRead(['state' => PlayState::Grace, 'expiresAt' => now()->addDays(3)->toDateTimeImmutable(), 'graceUntil' => now()->addDays(3)->toDateTimeImmutable()]);

    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe('grace')->and($subscription->grace_until->equalTo(now()->addDays(3)))->toBeTrue();
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
    Event::assertDispatched(SubscriptionLapsed::class, fn ($e) => $e->status === 'grace');
});

test('a hold from active goes through a zero-length grace in one step with exactly one lapse', function () {
    applyRead();

    applyRead(['state' => PlayState::OnHold]);

    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe('expired')->and(auditActions())->toBe(['purchase', 'expire']);
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
    Event::assertDispatched(SubscriptionLapsed::class, fn ($e) => $e->status === 'expired');
});

test('recovery from hold activates again with a recover entry and one activation', function () {
    applyRead();
    applyRead(['state' => PlayState::OnHold]);
    Event::fake([SubscriptionActivated::class, SubscriptionLapsed::class]);

    applyRead(['expiresAt' => now()->addDays(30)->toDateTimeImmutable()]);

    expect(Subscription::query()->sole()->status)->toBe('active')->and(auditActions())->toBe(['purchase', 'expire', 'recover']);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

test('a revocation revokes with the reason, is final, and a later active changes nothing', function () {
    applyRead();
    $purchase = app(PlayPurchaseRepository::class)->find($this->purchase->id);

    DB::transaction(fn () => app(ApplyPlayState::class)->revoke($purchase, $this->userId, 'refunded', now()->toDateTimeImmutable()));
    applyRead();

    expect(Subscription::query()->sole()->status)->toBe('revoked')
        ->and(AuditEntry::query()->orderBy('id')->get()->last()->detail)->toMatchArray(['action' => 'revoke', 'reason' => 'refunded'])
        ->and(auditActions())->toBe(['purchase', 'revoke']);
    Event::assertDispatched(SubscriptionLapsed::class, fn ($e) => $e->status === 'revoked');
});

test('a pending purchase or one canceled before it was paid creates no subscription', function (PlayState $state) {
    applyRead(['state' => $state]);

    expect(Subscription::query()->count())->toBe(0)->and(AuditEntry::query()->count())->toBe(0);
    Event::assertNothingDispatched();
})->with([PlayState::Pending, PlayState::PendingCanceled, PlayState::Expired, PlayState::OnHold]);

test('a license tester\'s purchase is recorded as a test in the subscription\'s source meta', function () {
    applyRead(['isTest' => true]);

    expect(Subscription::query()->sole()->source_meta['test'])->toBeTrue();
});
