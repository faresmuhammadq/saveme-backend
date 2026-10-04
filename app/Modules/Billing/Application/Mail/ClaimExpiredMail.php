<?php

namespace App\Modules\Billing\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The notice that a claim went unreviewed for 7 days (spec 020, FR-016):
 * names the reference by its last four characters only.
 */
final class ClaimExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $claimId,
        public readonly string $plan,
        public readonly string $tail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('billing.claim_expired.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'billing::mail.claim-expired',
            with: ['claimId' => $this->claimId, 'plan' => $this->plan, 'tail' => $this->tail, 'contact' => (string) config('billing.instapay.support_contact')],
        );
    }
}
