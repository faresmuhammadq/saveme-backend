<?php

namespace App\Modules\Sharing\Application\Jobs;

use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Sharing\Application\Mail\InvitationMail;
use App\Modules\Sharing\Domain\InvitationChannel;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The one job every email invitation dispatches, whether or not the address
 * belongs to an account (spec 016, R-4). Only here, off the request path, does
 * the outcome differ: the body is the registered or the unregistered one, in
 * the invitee's language or the inviter's. At most 5 invitation mails reach
 * one address in 24 hours across all inviters; beyond that nothing is sent
 * and nothing says so (silent). A closed invitation sends nothing.
 *
 * The payload carries the token, so the job is encrypted at rest in the
 * queue (ShouldBeEncrypted). The address and the token are never logged; a
 * failure logs a warning without any identifier (the id is debug-level).
 */
final class SendInvitationMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly string $invitationId,
        public readonly string $token,
    ) {}

    public function handle(InvitationRepository $invitations, InvitationAddresses $addresses, DisplayNames $names): void
    {
        $invitation = $invitations->find($this->invitationId);

        if ($invitation === null
            || $invitation->status !== InvitationStatus::Pending
            || $invitation->channel !== InvitationChannel::Email
            || $invitation->addressSealed === null
            || $invitation->addressLookup === null) {
            return;
        }

        $budget = 'sharing-mail:'.$invitation->addressLookup;

        if (RateLimiter::tooManyAttempts($budget, (int) config('sharing.throttle.mails_per_day_per_address'))) {
            return;
        }

        RateLimiter::hit($budget, 86400);

        $inviteeId = $addresses->userIdForLookup($invitation->addressLookup);

        Mail::to($addresses->open($invitation->invitedBy, $invitation->addressSealed))
            ->locale($addresses->localeOf($inviteeId ?? $invitation->invitedBy))
            ->send(new InvitationMail(
                config('sharing.links.invitation_url').'?token='.$this->token,
                $names->of([$invitation->invitedBy])[$invitation->invitedBy] ?? null,
                $invitation->role->value,
                $inviteeId !== null,
            ));
    }

    public function failed(?Throwable $exception): void
    {
        // The identifier is debug-level only; the warning joins the request through the correlation id.
        Log::warning('sharing_invitation_mail_failed');
        Log::debug('sharing_invitation_mail_failed_ids', ['invitation_id' => $this->invitationId]);
    }
}
