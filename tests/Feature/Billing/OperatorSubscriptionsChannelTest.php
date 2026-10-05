<?php

use App\Modules\Billing\Application\Subscriptions\OperatorSubscriptions;
use App\Modules\Billing\Domain\Refusals\AccountNotActive;
use App\Modules\Billing\Domain\Refusals\InvalidDays;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotExtendable;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotRevocable;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Str;

/*
 * The channel and account rules live in Billing's own commands, so they hold
 * for every caller (spec 021, FR-029; R-7).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->operator = (string) Str::uuid7();
    $this->plus = (string) Plan::query()->where('code', 'plus_monthly')->value('id');
    $this->ops = app(OperatorSubscriptions::class);
    $this->user = User::factory()->create()->id;
});

function operatorEntries(): int
{
    return AuditEntry::query()->where('event', 'entitlement_changed')->count();
}

test('extending refuses google play, instapay and lifetime subscriptions', function (string $provider, bool $lifetime) {
    $factory = Subscription::factory()->provider($provider);
    $sub = ($lifetime ? $factory->lifetime() : $factory)->create(['user_id' => $this->user]);
    $before = $sub->fresh()->current_period_end;

    expect(fn () => $this->ops->extend($this->operator, $sub->id, 10, 'x'))->toThrow(SubscriptionNotExtendable::class)
        ->and($sub->fresh()->current_period_end)->toEqual($before)
        ->and(operatorEntries())->toBe(0);
})->with([
    'google play' => ['google_play', false],
    'instapay' => ['instapay', false],
    'lifetime manual' => ['manual', true],
]);

test('revoking refuses google play and writes nothing', function () {
    $sub = Subscription::factory()->provider('google_play')->create(['user_id' => $this->user]);

    expect(fn () => $this->ops->revoke($this->operator, $sub->id, 'x'))->toThrow(SubscriptionNotRevocable::class)
        ->and($sub->fresh()->status)->toBe('active')
        ->and(operatorEntries())->toBe(0);
});

test('an instapay subscription can be revoked', function () {
    $sub = Subscription::factory()->provider('instapay')->create(['user_id' => $this->user]);

    $this->ops->revoke($this->operator, $sub->id, 'Refunded by hand');

    expect($sub->fresh()->status)->toBe('revoked')
        ->and(operatorEntries())->toBe(1);
});

test('granting and extending refuse an account that is not active', function (string $state) {
    $target = match ($state) {
        'pending deletion' => User::factory()->pendingDeletion()->create()->id,
        'purged' => tap(User::factory()->create(), fn (User $user) => User::query()->whereKey($user->id)->update(['state' => 'purged']))->id,
        'unknown' => (string) Str::uuid7(),
    };

    expect(fn () => $this->ops->grant($this->operator, $target, $this->plus, 30, 'x'))->toThrow(AccountNotActive::class)
        ->and(Subscription::query()->where('user_id', $target)->count())->toBe(0);

    if ($state !== 'unknown') {
        $sub = Subscription::factory()->create(['user_id' => $target]);
        $end = $sub->current_period_end;

        expect(fn () => $this->ops->extend($this->operator, $sub->id, 5, 'x'))->toThrow(AccountNotActive::class)
            ->and($sub->fresh()->current_period_end->equalTo($end))->toBeTrue();
    }

    expect(operatorEntries())->toBe(0);
})->with(['pending deletion', 'purged', 'unknown']);

test('more than 3,650 days is refused for a grant and an extension', function () {
    $sub = Subscription::factory()->create(['user_id' => $this->user]);

    expect(fn () => $this->ops->grant($this->operator, $this->user, $this->plus, 3651, 'x'))->toThrow(InvalidDays::class)
        ->and(fn () => $this->ops->extend($this->operator, $sub->id, 3651, 'x'))->toThrow(InvalidDays::class)
        ->and(operatorEntries())->toBe(0);

    $this->ops->grant($this->operator, $this->user, $this->plus, 3650, 'x');

    expect(operatorEntries())->toBe(1);
});
