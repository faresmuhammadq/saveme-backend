<?php

namespace App\Modules\Identity\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An Identity mail: one Blade view under `identity::mail`, every string from
 * `identity::mail` in the recipient's language (P-14). Only the two link
 * mails carry a secret, and only their own (FR-071).
 */
abstract class IdentityMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The mail's key under identity::mail and its view name.
     */
    abstract protected function key(): string;

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return [];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("identity::mail.{$this->key()}.subject", ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        /** @var view-string $view */
        $view = 'identity::mail.'.str_replace('_', '-', $this->key());

        return new Content(
            view: $view,
            with: ['mailKey' => $this->key(), ...$this->viewData()],
        );
    }
}
