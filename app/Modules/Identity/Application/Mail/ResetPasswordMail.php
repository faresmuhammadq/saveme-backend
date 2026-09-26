<?php

namespace App\Modules\Identity\Application\Mail;

/**
 * The reset link (60 minutes, single use).
 */
final class ResetPasswordMail extends IdentityMail
{
    public function __construct(public readonly string $secret) {}

    protected function key(): string
    {
        return 'reset_password';
    }

    protected function viewData(): array
    {
        return ['link' => config('identity.links.reset_url').'?token='.$this->secret];
    }
}
