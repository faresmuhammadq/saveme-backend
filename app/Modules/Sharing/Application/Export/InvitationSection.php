<?php

namespace App\Modules\Sharing\Application\Export;

use App\Modules\Identity\Application\Export\ExportSection;
use App\Modules\Identity\Application\Export\ExportTarget;
use App\Modules\Identity\Application\Export\ExportWriter;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use DateTimeZone;

/**
 * Sharing's file of an account export (spec 022, FR-002): the invitations
 * the person sent — wallet, channel, role, state, instants. Never the
 * invited address, the token or who answered.
 */
final class InvitationSection implements ExportSection
{
    public function __construct(private readonly InvitationRepository $invitations) {}

    public function write(ExportTarget $target, ExportWriter $writer): void
    {
        if (! $target->isAccount()) {
            return;
        }

        $writer->declare('invitations.json');

        foreach ($this->invitations->sentBy($target->userId) as $invitation) {
            $instant = fn (?\DateTimeImmutable $at): ?string => $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

            $writer->append('invitations.json', [
                'id' => $invitation->id,
                'wallet_id' => $invitation->walletId,
                'channel' => $invitation->channel->value,
                'role' => $invitation->role->value,
                'status' => $invitation->status->value,
                'expires_at' => $instant($invitation->expiresAt),
                'created_at' => $instant($invitation->createdAt),
                'updated_at' => $instant($invitation->updatedAt),
                'closed_at' => $instant($invitation->deletedAt),
            ]);
        }
    }
}
