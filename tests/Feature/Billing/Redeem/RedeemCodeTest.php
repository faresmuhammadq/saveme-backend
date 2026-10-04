<?php

use App\Modules\Billing\Application\RedeemCodes\RedeemCodes;
use App\Modules\Billing\Application\RedeemCodes\RedeemResult;
use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Domain\Refusals\InvalidRedeemCode;
use App\Modules\Billing\Domain\Refusals\RedeemCodeEmailMismatch;
use App\Modules\Billing\Domain\Refusals\RedeemCodeExpired;
use App\Modules\Billing\Domain\Refusals\RedeemCodeUnknown;
use App\Modules\Billing\Domain\Refusals\RedeemCodeUsed;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode as RedeemCodeRow;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Domain\Refusals\EmailNotVerified;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

/*
 * Redeeming a code (spec 020, US3, FR-026 to FR-032, FR-036, FR-037).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->user = person('ada@example.test');
});

/**
 * An approved claim for the address with an issued code, and the code as mailed.
 *
 * @return array{claim: PaymentClaim, code: RedeemCodeRow, typed: string}
 */
function issuedCodeFor(string $email = 'ada@example.test', string $plan = 'plus_yearly', array $code = []): array
{
    $typed = RedeemCode::generate()->display();
    $claim = PaymentClaim::factory()->forAddress($email)->forPlan($plan)->approved()->create();
    $row = RedeemCodeRow::factory()->forCode($typed)->state(['claim_id' => $claim->id, ...$code])->create();

    return ['claim' => $claim, 'code' => $row, 'typed' => $typed];
}

function redeemAs(string $userId, string $typed, string $locale = 'en'): RedeemResult
{
    app()->forgetScopedInstances();

    return app(RedeemCodes::class)->redeem($userId, $typed, $locale);
}

test('redeeming creates one InstaPay subscription for the claim\'s plan, redeems the code and closes the claim', function () {
    Event::fake([SubscriptionActivated::class]);
    ['claim' => $claim, 'code' => $code, 'typed' => $typed] = issuedCodeFor();

    $result = redeemAs($this->user->id, $typed);

    $subscription = Subscription::query()->sole();
    expect($subscription->user_id)->toBe($this->user->id)
        ->and($subscription->plan_id)->toBe($claim->plan_id)
        ->and($subscription->provider)->toBe('instapay')
        ->and($subscription->provider_ref)->toBe($claim->id)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->auto_renew)->toBeFalse()
        ->and($subscription->grace_until)->toBeNull()
        ->and($subscription->current_period_start->equalTo(now()))->toBeTrue()
        ->and($subscription->current_period_end->equalTo(now()->addYear()))->toBeTrue()
        ->and($result->subscriptionId)->toBe($subscription->id)
        ->and($result->planName)->toBe('Plus · Yearly');

    $code->refresh();
    expect($code->status)->toBe('redeemed')
        ->and($code->redeemed_by)->toBe($this->user->id)
        ->and($code->subscription_id)->toBe($subscription->id)
        ->and($code->redeemed_at->equalTo(now()))->toBeTrue()
        ->and($claim->fresh()->closed_at->equalTo(now()))->toBeTrue();

    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    Event::assertDispatched(SubscriptionActivated::class, fn (SubscriptionActivated $e): bool => $e->userId === $this->user->id && $e->subscriptionId === $subscription->id && $e->planId === $claim->plan_id);
});

test('redemption is audited once, by the user, with the redeem action and nothing else', function () {
    ['typed' => $typed] = issuedCodeFor();

    redeemAs($this->user->id, $typed);

    $entry = AuditEntry::query()->where('event', 'entitlement_changed')->sole();
    expect($entry)->actor_type->toBe('user')->actor_id->toBe($this->user->id)->subject_type->toBe('subscription')->subject_id->toBe(Subscription::query()->sole()->id)
        ->and($entry->detail)->toEqual(['action' => 'redeem', 'plan_id' => planIdByCode('plus_yearly')])
        ->and(json_encode($entry->detail))->not->toContain($typed)->not->toContain('ada@');
});

test('the answer names the redeemed subscription, the governing one and the entitlements', function () {
    ['typed' => $typed] = issuedCodeFor();

    $wire = redeemAs($this->user->id, $typed)->toWire();

    expect($wire['subscription'])->toMatchArray(['provider' => 'instapay', 'status' => 'active', 'plan' => ['id' => planIdByCode('plus_yearly'), 'name' => 'Plus · Yearly']])
        ->and($wire['governing'])->toMatchArray(['provider' => 'instapay', 'status' => 'active', 'auto_renew' => false])
        ->and($wire['entitlements']['plan']['name'])->toBe('Plus · Yearly')
        ->and($wire['entitlements']['features']->{'sharing.enabled'})->toBeTrue();
});

test('any spelling of the code is recognised', function (string $spelling) {
    $row = RedeemCodeRow::factory()->forCode('7KQ2-MX4D-9TRB-H3WE')->state(['claim_id' => PaymentClaim::factory()->forAddress('ada@example.test')->approved()->create()->id])->create();

    expect(redeemAs($this->user->id, $spelling)->subscriptionId)->toBe(Subscription::query()->sole()->id)
        ->and($row->fresh()->status)->toBe('redeemed');
})->with(['7KQ2-MX4D-9TRB-H3WE', '7kq2-mx4d-9trb-h3we', '7kq2mx4d9trbh3we', ' 7KQ2 MX4D 9TRB H3WE ']);

test('look-alike characters are folded', function () {
    $claim = PaymentClaim::factory()->forAddress('ada@example.test')->approved()->create();
    RedeemCodeRow::factory()->forCode('0011-0111-0011-1100')->state(['claim_id' => $claim->id])->create();

    expect(redeemAs($this->user->id, 'OOII-OLIL-OOII-LLOO')->subscriptionId)->not->toBeEmpty();
});

test('something that is not a code is invalid, an unknown or revoked code is unknown', function () {
    ['typed' => $revoked] = issuedCodeFor('ada@example.test', 'plus_yearly', ['status' => 'revoked', 'revoked_at' => now()]);

    expect(fn () => redeemAs($this->user->id, 'too-short'))->toThrow(InvalidRedeemCode::class)
        ->and(fn () => redeemAs($this->user->id, RedeemCode::generate()->display()))->toThrow(RedeemCodeUnknown::class)
        ->and(fn () => redeemAs($this->user->id, $revoked))->toThrow(RedeemCodeUnknown::class)
        ->and(Subscription::query()->count())->toBe(0);
});

test('the same account redeeming again gets the same result, with no second subscription, audit entry or event', function () {
    Event::fake([SubscriptionActivated::class]);
    ['typed' => $typed] = issuedCodeFor();

    $first = redeemAs($this->user->id, $typed);
    $this->travel(2)->days();
    $again = redeemAs($this->user->id, $typed);

    expect($again->subscriptionId)->toBe($first->subscriptionId)
        ->and($again->currentPeriodStart->format('c'))->toBe($first->currentPeriodStart->format('c'))
        ->and(Subscription::query()->count())->toBe(1)
        ->and(AuditEntry::query()->where('event', 'entitlement_changed')->count())->toBe(1);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

test('another account is told the code is used, before anything about its address', function () {
    ['typed' => $typed] = issuedCodeFor();
    redeemAs($this->user->id, $typed);
    $other = person('bob@example.test');

    expect(fn () => redeemAs($other->id, $typed))->toThrow(RedeemCodeUsed::class)
        ->and(Subscription::query()->count())->toBe(1);
});

test('a code past its 30 days is expired whether or not the sweep ran, and creates nothing', function (string $state) {
    ['typed' => $typed] = issuedCodeFor('ada@example.test', 'plus_yearly', $state === 'swept' ? ['status' => 'expired', 'expires_at' => now()->subDay()] : ['expires_at' => now()->subSecond()]);

    expect(fn () => redeemAs($this->user->id, $typed))->toThrow(RedeemCodeExpired::class)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'entitlement_changed')->count())->toBe(0);
})->with(['swept', 'not yet swept']);

test('a code is valid up to the last instant before its expiry', function () {
    ['typed' => $typed] = issuedCodeFor('ada@example.test', 'plus_yearly', ['expires_at' => now()->addSecond()]);

    expect(redeemAs($this->user->id, $typed)->subscriptionId)->not->toBeEmpty();
});

test('an account with another address is refused with the mismatch, and nothing is created', function () {
    ['typed' => $typed, 'code' => $code] = issuedCodeFor('someone.else@example.test');

    expect(fn () => redeemAs($this->user->id, $typed))->toThrow(RedeemCodeEmailMismatch::class)
        ->and(Subscription::query()->count())->toBe(0)
        ->and($code->fresh()->status)->toBe('issued');
});

test('the right address, unverified, is refused as not verified; a wrong address wins over that', function () {
    $unverified = person('new@example.test', verified: false);
    ['typed' => $mine] = issuedCodeFor('new@example.test');
    ['typed' => $theirs] = issuedCodeFor('someone.else@example.test');

    expect(fn () => redeemAs($unverified->id, $mine))->toThrow(EmailNotVerified::class)
        ->and(fn () => redeemAs($unverified->id, $theirs))->toThrow(RedeemCodeEmailMismatch::class)
        ->and(Subscription::query()->count())->toBe(0);
});

test('an account registered after the code was issued can redeem once verified', function () {
    ['typed' => $typed] = issuedCodeFor('late@example.test');
    $late = person('late@example.test');

    expect(redeemAs($late->id, $typed)->subscriptionId)->not->toBeEmpty();
});

test('an account that changed its address no longer holds the claim\'s', function () {
    ['typed' => $typed] = issuedCodeFor('ada@example.test');
    $other = person('bob@example.test');

    expect(fn () => redeemAs($other->id, $typed))->toThrow(RedeemCodeEmailMismatch::class);
});

test('the unique provider reference is the last guard against a second subscription for one claim', function () {
    ['claim' => $claim, 'typed' => $typed] = issuedCodeFor();
    redeemAs($this->user->id, $typed);

    expect(fn () => Subscription::factory()->provider('instapay')->create(['user_id' => $this->user->id, 'provider_ref' => $claim->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a monthly plan gives one calendar month', function () {
    ['typed' => $typed] = issuedCodeFor('ada@example.test', 'plus_monthly');

    redeemAs($this->user->id, $typed);

    expect(Subscription::query()->sole()->current_period_end->equalTo(now()->addMonth()))->toBeTrue();
});
