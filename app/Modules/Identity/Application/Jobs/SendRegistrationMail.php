<?php

namespace App\Modules\Identity\Application\Jobs;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Mail\VerifyAddressMail;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * The welcome mail for a new account, with its verification link (FR-010).
 */
final class SendRegistrationMail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $userId,
        public readonly string $locale,
    ) {}

    public function handle(Accounts $accounts, LinkRepository $links): void
    {
        Mail::to($accounts->address($this->userId))
            ->locale($this->locale)
            ->send(new VerifyAddressMail($links->issue($this->userId, LinkKind::VerifyAddress)));
    }
}
