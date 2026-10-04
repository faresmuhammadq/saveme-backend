<?php

namespace App\Modules\Billing\Application\Jobs;

use App\Modules\Billing\Application\Mail\RenewalReminderMail;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The renewal reminder for one InstaPay subscription (spec 020, FR-034,
 * FR-035). The address and language are read from the account at send time,
 * so a purged account is skipped and a changed address is honoured.
 */
final class SendRenewalReminderMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly string $userId,
        public readonly string $subscriptionId,
    ) {}

    public function handle(SubscriptionRepository $subscriptions, PlanRepository $plans, ClaimAddresses $addresses): void
    {
        $subscription = $subscriptions->find($this->subscriptionId);
        $account = $addresses->addressAndLocaleOf($this->userId);

        if ($subscription === null || $subscription->currentPeriodEnd === null || $account === null) {
            return;
        }

        Mail::to($account['address'])
            ->locale($account['locale'])
            ->send(new RenewalReminderMail(
                $plans->find($subscription->planId)?->nameIn($account['locale']) ?? '',
                $subscription->currentPeriodEnd->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
                (string) config('billing.instapay.renew_url'),
            ));
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('billing_renewal_reminder_mail_failed');
        Log::debug('billing_renewal_reminder_mail_failed_ids', ['subscription_id' => $this->subscriptionId]);
    }
}
