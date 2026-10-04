<?php

namespace App\Modules\Billing\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The rejection mail (spec 020, FR-018): the reason from the fixed list, in
 * the claim's language, and where to get help. Never the operator's note.
 */
final class ClaimRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $claimId,
        public readonly string $plan,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('billing.claim_rejected.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'billing::mail.claim-rejected',
            with: ['claimId' => $this->claimId, 'plan' => $this->plan, 'reason' => $this->reason, 'contact' => (string) config('billing.instapay.support_contact')],
        );
    }
}
