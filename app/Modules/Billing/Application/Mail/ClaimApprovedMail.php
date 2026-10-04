<?php

namespace App\Modules\Billing\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The mail that carries a Redeem Code (spec 020, FR-017, R-13): the grouped
 * code, the plan, how to enter it, which address it works for, its last day
 * and where to get help. The only place the plaintext code exists after the
 * approving transaction.
 */
final class ClaimApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $plan,
        public readonly string $expiresOn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('billing.claim_approved.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'billing::mail.claim-approved',
            with: ['code' => $this->code, 'plan' => $this->plan, 'expiresOn' => $this->expiresOn, 'contact' => (string) config('billing.instapay.support_contact')],
        );
    }
}
