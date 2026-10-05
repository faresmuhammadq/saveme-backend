<?php

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Subscriptions\OperatorSubscriptions;
use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\InvalidDays;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\PlanNotGrantable;
use App\Modules\Billing\Domain\Refusals\SubscriptionRevoked;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Operators grant, extend and revoke (spec 009, US4; ADR-0013).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->operator = (string) Str::uuid7();
    // Spec 021, R-7: an operator grants and extends only for a real, active account.
    $this->user = User::factory()->create()->id;
    $this->plus = (string) Plan::query()->where('code', 'plus_monthly')->value('id');
    $this->ops = app(OperatorSubscriptions::class);
});

function sharingFor(string $userId): bool
{
    app()->forgetScopedInstances();

    return app(EntitlementService::class)->forUser($userId)->allows(Feature::SharingEnabled);
}

test('a grant creates a manual 30-day subscription, audited once and announced', function () {
    Event::fake([SubscriptionActivated::class]);

    $granted = $this->ops->grant($this->operator, $this->user, $this->plus, 30, '  Goodwill  ');

    $row = Subscription::query()->sole();
    expect($row->provider)->toBe('manual')
        ->and($row->provider_ref)->toStartWith("op:{$this->operator}:")
        ->and($row->auto_renew)->toBeFalse()
        ->and($row->current_period_end->equalTo(now()->addDays(30)))->toBeTrue()
        ->and(sharingFor($this->user))->toBeTrue();

    $entry = AuditEntry::query()->where('event', 'entitlement_changed')->sole();
    expect($entry)
        ->actor_type->toBe('operator')
        ->actor_id->toBe($this->operator)
        ->subject_type->toBe('subscription')
        ->subject_id->toBe($granted->id)
        ->and($entry->detail)->toEqual(['action' => 'grant', 'plan_id' => $this->plus, 'days' => 30, 'reason' => 'Goodwill']);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

test('a grant without days is for life', function () {
    $this->ops->grant($this->operator, $this->user, $this->plus, null, 'Reviewer account');

    expect(Subscription::query()->sole()->current_period_end)->toBeNull();
});

test('the free plan, a retired plan or an unknown plan cannot be granted', function (string $which) {
    $planId = match ($which) {
        'free' => (string) Plan::query()->where('code', 'free')->value('id'),
        'retired' => Plan::factory()->create(['status' => 'retired'])->id,
        'unknown' => (string) Str::uuid7(),
    };

    expect(fn () => $this->ops->grant($this->operator, $this->user, $planId, 30, 'x'))->toThrow(PlanNotGrantable::class);
})->with(['free', 'retired', 'unknown']);

test('a blank or overlong reason, or zero days, is refused', function () {
    expect(fn () => $this->ops->grant($this->operator, $this->user, $this->plus, 30, '   '))->toThrow(InvalidReason::class)
        ->and(fn () => $this->ops->grant($this->operator, $this->user, $this->plus, 30, str_repeat('a', 501)))->toThrow(InvalidReason::class)
        ->and(fn () => $this->ops->grant($this->operator, $this->user, $this->plus, 0, 'x'))->toThrow(InvalidDays::class);
});

test('extending moves the end later', function () {
    $sub = Subscription::factory()->create(['user_id' => $this->user]);
    $end = $sub->current_period_end;

    $this->ops->extend($this->operator, $sub->id, 15, 'Support fix');

    expect($sub->fresh()->current_period_end->equalTo($end->addDays(15)))->toBeTrue();
});

test('extending an expired or grace subscription makes it active again', function (string $state) {
    Event::fake([SubscriptionActivated::class]);
    $sub = Subscription::factory()->{$state}()->create(['user_id' => $this->user]);

    $this->ops->extend($this->operator, $sub->id, 30, 'Back on');

    expect($sub->fresh()->status)->toBe('active')
        ->and($sub->fresh()->current_period_end->equalTo(now()->addDays(30)) || $state === 'grace')->toBeTrue()
        ->and(sharingFor($this->user))->toBeTrue();
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
})->with(['expired', 'grace']);

test('a revoked subscription cannot be extended', function () {
    $sub = Subscription::factory()->revoked()->create(['user_id' => $this->user]);

    expect(fn () => $this->ops->extend($this->operator, $sub->id, 30, 'x'))->toThrow(SubscriptionRevoked::class);
});

test('revoking ends access for good, once', function () {
    Event::fake([SubscriptionLapsed::class]);
    $sub = Subscription::factory()->create(['user_id' => $this->user]);

    $this->ops->revoke($this->operator, $sub->id, 'Chargeback');
    $this->ops->revoke($this->operator, $sub->id, 'Again');

    expect($sub->fresh()->status)->toBe('revoked')
        ->and(sharingFor($this->user))->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'entitlement_changed')->count())->toBe(1);
    Event::assertDispatched(SubscriptionLapsed::class, fn (SubscriptionLapsed $e): bool => $e->status === 'revoked');
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
});

test('no audit entry carries an amount', function () {
    $sub = $this->ops->grant($this->operator, $this->user, $this->plus, 30, 'x');
    $this->ops->extend($this->operator, $sub->id, 5, 'y');
    $this->ops->revoke($this->operator, $sub->id, 'z');

    foreach (AuditEntry::query()->where('event', 'entitlement_changed')->get() as $entry) {
        expect(json_encode($entry->detail))->not->toContain('14900')->not->toContain('amount');
    }
});
