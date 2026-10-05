<?php

use App\Modules\Billing\Application\Departure\EndSubscriptions;
use App\Modules\Billing\Application\Subscriptions\PurgeEndedSubscriptions;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Billing's step of an account purge (spec 022, FR-047): internal
 * subscriptions end, Google Play is left to its provider, every row is
 * stamped for the 400-day delete.
 */

beforeEach(function () {
    $this->d = person();
    $this->manual = Subscription::factory()->create(['user_id' => $this->d->id, 'provider' => 'manual']);
    $this->instapay = Subscription::factory()->provider('instapay')->grace()->create(['user_id' => $this->d->id]);
    $this->play = Subscription::factory()->provider('google_play')->create(['user_id' => $this->d->id]);
    $this->expired = Subscription::factory()->provider('instapay')->expired()->create(['user_id' => $this->d->id]);
    $this->theirs = Subscription::factory()->create(['user_id' => person('other@example.test')->id]);
});

test('granting manual and instapay subscriptions are revoked, Google Play untouched, every row of the person stamped, a second call a no-op', function () {
    Event::fake([SubscriptionLapsed::class]);
    $step = app(EndSubscriptions::class);

    $counts = $step->purge($this->d->id, purgeContextFor($this->d->id));

    $rows = DB::table('subscriptions')->where('user_id', $this->d->id)->get()->keyBy('id');
    expect($step->step())->toBe(PurgeStep::Subscriptions)
        ->and($counts->get('subscriptions_ended'))->toBe(2)
        ->and($rows[$this->manual->id]->status)->toBe('revoked')
        ->and($rows[$this->instapay->id]->status)->toBe('revoked')
        ->and($rows[$this->play->id]->status)->toBe('active')
        ->and($rows[$this->expired->id]->status)->toBe('expired')
        ->and($rows->pluck('ended_by_account_purge_at')->filter()->count())->toBe(4)
        ->and(DB::table('subscriptions')->find($this->theirs->id)->ended_by_account_purge_at)->toBeNull();
    Event::assertDispatchedTimes(SubscriptionLapsed::class, 2);

    $entries = DB::table('audit_entries')->where('event', 'entitlement_changed')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('actor_type')->unique()->all())->toBe(['system'])
        ->and($entries->pluck('actor_id')->filter()->all())->toBe([])
        ->and(json_decode($entries[0]->detail, true)['reason'])->toBe('account_deleted');

    $again = $step->purge($this->d->id, purgeContextFor($this->d->id));
    expect($again->get('subscriptions_ended'))->toBe(0)
        ->and(DB::table('audit_entries')->where('event', 'entitlement_changed')->count())->toBe(2);
});

test('the person no longer governs anything after the step', function () {
    app(EndSubscriptions::class)->purge($this->d->id, purgeContextFor($this->d->id));

    $granting = DB::table('subscriptions')->where('user_id', $this->d->id)->whereIn('status', ['active', 'grace'])->get();

    expect($granting->pluck('provider')->all())->toBe(['google_play']);
});

test('rows stamped by a purge are deleted 400 days later, and not before', function () {
    app(EndSubscriptions::class)->purge($this->d->id, purgeContextFor($this->d->id));

    $this->travel(399)->days();
    app(PurgeEndedSubscriptions::class)->run();
    expect(DB::table('subscriptions')->where('user_id', $this->d->id)->count())->toBe(4);

    $this->travel(2)->days();
    expect(app(PurgeEndedSubscriptions::class)->run())->toBe(4)
        ->and(DB::table('subscriptions')->where('id', $this->theirs->id)->exists())->toBeTrue();
});
