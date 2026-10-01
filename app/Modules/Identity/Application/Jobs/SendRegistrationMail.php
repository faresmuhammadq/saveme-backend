<?php

namespace App\Modules\Identity\Application\Jobs;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Mail\VerifyAddressMail;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The welcome mail for a new account, with its verification link (FR-010).
 *
 * The link is issued when the worker runs, so a resend the person asked for
 * in the meantime is newer: the welcome mail then stays silent rather than
 * superseding that link. The link and the send share one transaction, so a
 * failed send leaves no link behind and the retry starts clean; a redelivered
 * job finds its own link and sends nothing twice.
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
        DB::transaction(function () use ($accounts, $links): void {
            $secret = $links->issueFirst($this->userId, LinkKind::VerifyAddress);

            if ($secret === null) {
                return;
            }

            Mail::to($accounts->address($this->userId))
                ->locale($this->locale)
                ->send(new VerifyAddressMail($secret));
        });
    }
}
