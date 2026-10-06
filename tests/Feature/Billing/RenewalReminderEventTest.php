<?php

use App\Modules\Billing\Application\Jobs\SendRenewalReminderMail;
use App\Modules\Billing\Application\Reminders\SendRenewalReminders;
use App\Modules\Billing\Domain\Events\SubscriptionRenewalDue;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * The renewal reminder also publishes SubscriptionRenewalDue (spec 023, FR-027),
 * beside the mail, which is unchanged.
 */

beforeEach(function () {
    $this->freezeTime();
    $this->user = person('ada@example.test');
});

test('one event per stamped subscription, carrying only identifiers', function () {
    Queue::fake();
    Event::fake([SubscriptionRenewalDue::class]);
    $subscription = Subscription::factory()->provider('instapay')->forPlan('plus_monthly')->endingIn(6)->create(['user_id' => $this->user->id]);

    app(SendRenewalReminders::class)->run();

    Event::assertDispatchedTimes(SubscriptionRenewalDue::class, 1);
    Event::assertDispatched(SubscriptionRenewalDue::class, fn (SubscriptionRenewalDue $event): bool => $event->userId === $this->user->id
        && $event->subscriptionId === $subscription->id
        && ! empty($event->eventId)
        && ! empty($event->occurredAt));
    Queue::assertPushed(SendRenewalReminderMail::class, 1);
});

test('a rerun dispatches nothing more', function () {
    Queue::fake();
    Event::fake([SubscriptionRenewalDue::class]);
    Subscription::factory()->provider('instapay')->forPlan('plus_monthly')->endingIn(6)->create(['user_id' => $this->user->id]);

    app(SendRenewalReminders::class)->run();
    app(SendRenewalReminders::class)->run();

    Event::assertDispatchedTimes(SubscriptionRenewalDue::class, 1);
    Queue::assertPushed(SendRenewalReminderMail::class, 1);
});

test('nothing is published for a subscription that is not reminded', function () {
    Queue::fake();
    Event::fake([SubscriptionRenewalDue::class]);
    Subscription::factory()->provider('google_play')->endingIn(5)->create(['user_id' => $this->user->id]);

    app(SendRenewalReminders::class)->run();

    Event::assertNotDispatched(SubscriptionRenewalDue::class);
});

test('the push follows the event end to end', function () {
    Queue::fake([SendRenewalReminderMail::class]);
    $this->travelTo(now()->setTime(10, 0));
    $phone = registerPhone($this->user, 'phone');
    $subscription = Subscription::factory()->provider('instapay')->forPlan('plus_monthly')->endingIn(6)->create(['user_id' => $this->user->id]);

    app(SendRenewalReminders::class)->run();

    expect($phone)->not->toBeNull()
        ->and(DB::table('push_notifications')->where('dedupe_key', 'renewal:'.$subscription->id)->value('type'))->toBe('subscription_ending');
});
