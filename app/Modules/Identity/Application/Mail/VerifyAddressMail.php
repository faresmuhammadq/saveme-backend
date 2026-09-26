<?php

namespace App\Modules\Identity\Application\Mail;

/**
 * Welcome, and the link that verifies the address (24 hours, single use).
 */
final class VerifyAddressMail extends IdentityMail
{
    public function __construct(public readonly string $secret) {}

    protected function key(): string
    {
        return 'verify_address';
    }

    protected function viewData(): array
    {
        return ['link' => config('identity.links.verify_url').'?token='.$this->secret];
    }
}
