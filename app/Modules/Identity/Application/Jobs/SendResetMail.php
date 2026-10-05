<?php

namespace App\Modules\Identity\Application\Jobs;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Mail\ResetPasswordMail;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * The one job every reset request dispatches, registered or not (spec 007,
 * R-10). Only here, off the request path, does the outcome differ: a mail is
 * sent only when the address belongs to an account.
 */
final class SendResetMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $lookup) {}

    public function handle(UserRepository $users, ProfileRepository $profiles, LinkRepository $links, Accounts $accounts): void
    {
        $user = $users->findByLookup($this->lookup);

        if ($user === null || $user->state->isGone()) {
            return;
        }

        Mail::to($accounts->address($user->id))
            ->locale($profiles->localeOf($user->id))
            ->send(new ResetPasswordMail($links->issue($user->id, LinkKind::ResetPassword)));
    }
}
