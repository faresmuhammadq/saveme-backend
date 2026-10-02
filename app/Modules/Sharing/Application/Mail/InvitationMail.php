<?php

namespace App\Modules\Sharing\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation mail (spec 016, FR-005): one Blade view under
 * `sharing::mail`, every string from `sharing::mail` in the recipient's
 * language (P-14). Registered and unregistered recipients get different
 * bodies. It names the inviter (when they set a name) and the role, carries
 * the one link, and names no wallet and no amount (C9).
 */
final class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $link,
        public readonly ?string $inviter,
        public readonly string $role,
        public readonly bool $registered,
    ) {}

    private function key(): string
    {
        return $this->registered ? 'invitation_registered' : 'invitation_unregistered';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("sharing::mail.{$this->key()}.subject", ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        /** @var view-string $view */
        $view = 'sharing::mail.'.str_replace('_', '-', $this->key());

        return new Content(
            view: $view,
            with: ['mailKey' => $this->key(), 'link' => $this->link, 'inviter' => $this->inviter, 'role' => $this->role],
        );
    }
}
