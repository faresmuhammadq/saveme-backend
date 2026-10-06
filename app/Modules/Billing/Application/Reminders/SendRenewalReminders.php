<?php

namespace App\Modules\Billing\Application\Reminders;

use App\Modules\Billing\Application\Jobs\SendRenewalReminderMail;
use App\Modules\Billing\Domain\Events\SubscriptionRenewalDue;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\GoverningSubscription;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The daily renewal reminder (spec 020, FR-034, FR-035): one mail per
 * `instapay` subscription whose billing period ends within 7 days, when it is
 * the governing subscription (so nothing granting ends later) and no reminder
 * was queued before. Recording the reminder is a conditional update, so two
 * runs never queue two mails. Lifetime subscriptions have no end and get none.
 */
final class SendRenewalReminders
{
    public function __construct(private readonly SubscriptionRepository $subscriptions) {}

    /**
     * @return int reminders queued
     */
    public function run(?DateTimeImmutable $now = null): int
    {
        $now ??= now()->toDateTimeImmutable();
        $queued = 0;

        foreach ($this->subscriptions->dueForReminder($now, (int) config('billing.instapay.reminder_days')) as $candidate) {
            $governing = GoverningSubscription::among($this->subscriptions->forUser($candidate->userId), $now);

            if ($governing === null || $governing->id !== $candidate->id) {
                continue;
            }

            DB::transaction(function () use ($candidate, $now, &$queued): void {
                if ($this->subscriptions->markReminded($candidate->id, $now)) {
                    SendRenewalReminderMail::dispatch($candidate->userId, $candidate->id)->afterCommit();
                    event(new SubscriptionRenewalDue(
                        (string) Str::uuid7(),
                        $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                        $candidate->userId,
                        $candidate->id,
                    ));
                    $queued++;
                }
            });
        }

        Log::info('billing_renewal_reminders_queued', ['queued' => $queued]);

        return $queued;
    }
}
