<?php

namespace App\Modules\Admin\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an operator that a sign-in cancelled an account deletion (spec 007,
 * FR-056). Identifiers and times only: no address, no name, no device name,
 * which is user-entered text and could itself identify a person.
 */
final class OperatorNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectUserId,
        public readonly string $occurredAtUtc,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('admin::notifications.kinds.deletion_cancelled_by_sign_in.title'));
    }

    public function content(): Content
    {
        return new Content(view: 'admin::mail.operator-notification', with: [
            'userId' => $this->subjectUserId,
            'utc' => $this->occurredAtUtc,
            'local' => now()->parse($this->occurredAtUtc)->setTimezone((string) config('admin.timezone'))->format('Y-m-d H:i T'),
        ]);
    }
}
