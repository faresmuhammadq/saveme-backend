<?php

namespace App\Modules\Identity\Application\Verification;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Mail\VerifyAddressMail;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the verification mail again (FR-012). Every earlier link stops
 * working. An address already verified gets no mail and the same answer.
 */
final class ResendVerification
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LinkRepository $links,
        private readonly ProfileRepository $profiles,
        private readonly Accounts $accounts,
    ) {}

    public function handle(string $userId): void
    {
        if ($this->users->find($userId)?->isVerified() !== false) {
            return;
        }

        Mail::to($this->accounts->address($userId))
            ->locale($this->profiles->localeOf($userId))
            ->queue(new VerifyAddressMail($this->links->issue($userId, LinkKind::VerifyAddress)));
    }
}
