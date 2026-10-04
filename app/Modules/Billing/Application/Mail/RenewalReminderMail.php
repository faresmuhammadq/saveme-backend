<?php

namespace App\Modules\Billing\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The renewal reminder (spec 020, FR-034, FR-035): the plan, its last day and
 * the configured link to renew.
 */
final class RenewalReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $plan,
        public readonly string $endsOn,
        public readonly string $renewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('billing.renewal_reminder.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'billing::mail.renewal-reminder',
            with: ['plan' => $this->plan, 'endsOn' => $this->endsOn, 'renewUrl' => $this->renewUrl],
        );
    }
}
