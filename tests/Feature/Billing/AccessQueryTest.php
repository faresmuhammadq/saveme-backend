<?php

use App\Modules\Billing\Application\Subscriptions\AccessQuery;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Str;

/*
 * What an operator sees of a person's subscriptions: no price, no provider
 * reference (spec 021, FR-021, FR-026 to FR-029; R-7).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->user = (string) Str::uuid7();
    $this->query = app(AccessQuery::class);
});

test('a person with nothing has no subscriptions and no governing one', function () {
    $view = $this->query->forUser($this->user);

    expect($view->subscriptions)->toBe([])
        ->and($view->governingSubscriptionId)->toBeNull();
});

test('subscriptions carry plan names, channel, status and dates, newest first', function () {
    $older = Subscription::factory()->create(['user_id' => $this->user, 'created_at' => now()->subDays(5)]);
    $newer = Subscription::factory()->forPlan('plus_yearly')->provider('instapay')->create(['user_id' => $this->user, 'created_at' => now()->subDay()]);

    $view = $this->query->forUser($this->user);

    expect(array_map(fn ($s) => $s->id, $view->subscriptions))->toBe([$newer->id, $older->id]);
    $first = $view->subscriptions[0];
    expect($first->planName['en'])->toBe('Plus · Yearly')
        ->and($first->provider)->toBe('instapay')
        ->and($first->status)->toBe('active')
        ->and($first->periodEnd)->not->toBeNull()
        ->and(array_keys($first->toArray()))->not->toContain('providerRef', 'provider_ref', 'price', 'amount_minor');
});

test('the governing subscription follows spec 009 precedence', function () {
    $short = Subscription::factory()->endingIn(10)->create(['user_id' => $this->user]);
    $long = Subscription::factory()->endingIn(200)->create(['user_id' => $this->user]);
    Subscription::factory()->expired()->create(['user_id' => $this->user]);

    expect($this->query->forUser($this->user)->governingSubscriptionId)->toBe($long->id);

    $life = Subscription::factory()->lifetime()->create(['user_id' => $this->user]);

    expect($this->query->forUser($this->user)->governingSubscriptionId)->toBe($life->id)
        ->and($short->id)->not->toBe($life->id);
});

test('only a manual subscription with an end that is not revoked can be extended', function (string $provider, string $state, bool $expected) {
    $factory = Subscription::factory()->provider($provider);
    $factory = match ($state) {
        'lifetime' => $factory->lifetime(),
        'revoked' => $factory->revoked(),
        'expired' => $factory->expired(),
        default => $factory,
    };
    $factory->create(['user_id' => $this->user]);

    expect($this->query->forUser($this->user)->subscriptions[0]->canExtend)->toBe($expected);
})->with([
    'manual with an end' => ['manual', 'active', true],
    'manual expired' => ['manual', 'expired', true],
    'manual lifetime' => ['manual', 'lifetime', false],
    'manual revoked' => ['manual', 'revoked', false],
    'instapay' => ['instapay', 'active', false],
    'google play' => ['google_play', 'active', false],
]);

test('a manual or instapay subscription that is not revoked can be revoked', function (string $provider, string $state, bool $expected) {
    $factory = Subscription::factory()->provider($provider);
    $state === 'revoked' ? $factory = $factory->revoked() : null;
    $factory->create(['user_id' => $this->user]);

    expect($this->query->forUser($this->user)->subscriptions[0]->canRevoke)->toBe($expected);
})->with([
    'manual' => ['manual', 'active', true],
    'instapay' => ['instapay', 'active', true],
    'google play' => ['google_play', 'active', false],
    'revoked manual' => ['manual', 'revoked', false],
]);

test('only active non-default plans can be offered for a grant', function () {
    Plan::factory()->create(['status' => 'retired', 'name' => ['en' => 'Retired plan', 'ar' => 'x']]);

    $offered = collect($this->query->grantablePlans());

    expect($offered->pluck('name.en')->all())->not->toContain('Retired plan', 'Free')
        ->and($offered)->not->toBeEmpty()
        ->and($offered->first())->toHaveKeys(['id', 'name']);
});
