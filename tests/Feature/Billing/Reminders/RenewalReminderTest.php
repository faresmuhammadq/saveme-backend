<?php

use App\Modules\Billing\Application\Jobs\SendRenewalReminderMail;
use App\Modules\Billing\Application\Mail\RenewalReminderMail;
use App\Modules\Billing\Application\Reminders\SendRenewalReminders;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * The renewal reminder (spec 020, US5, FR-034, FR-035, SC-004).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->user = person('ada@example.test');
});

function instapaySubscription(string $userId, int $endsInDays = 6, string $plan = 'plus_monthly'): Subscription
{
    return Subscription::factory()->provider('instapay')->forPlan($plan)->endingIn($endsInDays)->create(['user_id' => $userId]);
}

test('a governing InstaPay subscription ending in 6 days gets exactly one reminder, however often it runs', function () {
    Queue::fake();
    $subscription = instapaySubscription($this->user->id);

    expect(app(SendRenewalReminders::class)->run())->toBe(1)
        ->and(app(SendRenewalReminders::class)->run())->toBe(0);

    expect($subscription->fresh()->renewal_reminded_at->equalTo(now()))->toBeTrue();
    Queue::assertPushed(SendRenewalReminderMail::class, 1);
    Queue::assertPushed(SendRenewalReminderMail::class, fn (SendRenewalReminderMail $job): bool => $job->userId === $this->user->id && $job->subscriptionId === $subscription->id);
});

test('the window is the next 7 days', function (int $days, int $expected) {
    Queue::fake();
    instapaySubscription($this->user->id, $days);

    expect(app(SendRenewalReminders::class)->run())->toBe($expected);
})->with([[6, 1], [7, 1], [8, 0]]);

test('nothing goes for a subscription that ended, or one that is not InstaPay, or has no end', function (string $case) {
    Queue::fake();
    match ($case) {
        'ended' => Subscription::factory()->provider('instapay')->expired()->create(['user_id' => $this->user->id]),
        'in grace' => Subscription::factory()->provider('instapay')->grace(2)->create(['user_id' => $this->user->id]),
        'play' => Subscription::factory()->provider('google_play')->endingIn(5)->create(['user_id' => $this->user->id]),
        'manual' => Subscription::factory()->provider('manual')->endingIn(5)->create(['user_id' => $this->user->id]),
        'lifetime' => Subscription::factory()->provider('instapay')->lifetime()->create(['user_id' => $this->user->id]),
    };

    expect(app(SendRenewalReminders::class)->run())->toBe(0);
    Queue::assertNothingPushed();
})->with(['ended', 'in grace', 'play', 'manual', 'lifetime']);

test('an InstaPay subscription that does not govern gets none, because something ends later', function () {
    Queue::fake();
    $instapay = instapaySubscription($this->user->id, 5);
    Subscription::factory()->provider('google_play')->endingIn(200)->create(['user_id' => $this->user->id]);

    expect(app(SendRenewalReminders::class)->run())->toBe(0)
        ->and($instapay->fresh()->renewal_reminded_at)->toBeNull();
    Queue::assertNothingPushed();
});

test('the reminder goes to the account\'s address in its profile language, with the plan, the end date and the renewal link', function () {
    Mail::fake();
    config(['billing.instapay.renew_url' => 'https://saveme.test/renew']);
    Profile::query()->where('user_id', $this->user->id)->update(['locale' => 'ar']);
    $subscription = instapaySubscription($this->user->id, 6, 'plus_yearly');

    app()->call([new SendRenewalReminderMail($this->user->id, $subscription->id), 'handle']);

    Mail::assertSent(RenewalReminderMail::class, 1);
    Mail::assertSent(RenewalReminderMail::class, function (RenewalReminderMail $mail) use ($subscription): bool {
        $html = $mail->render();

        return $mail->hasTo('ada@example.test')
            && $mail->locale === 'ar'
            && str_contains($html, 'بلس · سنوي')
            && str_contains($html, $subscription->current_period_end->format('Y-m-d'))
            && str_contains($html, 'https://saveme.test/renew')
            && str_contains($html, 'dir="rtl"');
    });
});

test('an English profile gets the English mail', function () {
    Mail::fake();
    $subscription = instapaySubscription($this->user->id);

    app()->call([new SendRenewalReminderMail($this->user->id, $subscription->id), 'handle']);

    Mail::assertSent(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => $mail->locale === 'en' && str_contains($mail->render(), 'Renew your plan'));
});

test('a purged account is sent nothing', function () {
    Mail::fake();
    $subscription = instapaySubscription($this->user->id);
    User::query()->whereKey($this->user->id)->update(['state' => 'purged', 'purged_at' => now()]);

    app()->call([new SendRenewalReminderMail($this->user->id, $subscription->id), 'handle']);

    Mail::assertNothingSent();
});

test('the command runs the daily reminder', function () {
    Queue::fake();
    instapaySubscription($this->user->id);

    expect(Artisan::call('billing:send-renewal-reminders'))->toBe(0)->and(Artisan::output())->toContain('1 reminder(s) queued.');
});
