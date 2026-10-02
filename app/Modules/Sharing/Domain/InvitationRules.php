<?php

namespace App\Modules\Sharing\Domain;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use DateTimeImmutable;
use LogicException;

/**
 * What an invitation may and may not become (spec 016, FR-005, FR-008).
 * Every member is fixed once created; the only transition is pending to a
 * closed state, which erases the invited address.
 */
final class InvitationRules
{
    /**
     * Email when an address is given, link when it is null.
     */
    public static function channelFor(?string $inviteeEmail): InvitationChannel
    {
        return $inviteeEmail === null ? InvitationChannel::Link : InvitationChannel::Email;
    }

    /**
     * The members of a write that differ from the stored invitation, each as
     * IMMUTABLE_FIELD at its pointer, collected. Empty means an identical
     * replay, whatever version it carries. The address is compared by lookup
     * value, so a different spelling of the same address is identical.
     *
     * @return list<FieldError>
     */
    public static function immutableViolations(InvitationRecord $stored, InvitedRole $role, ?string $requestedLookup): array
    {
        $errors = [];

        if ($role !== $stored->role) {
            $errors[] = new FieldError('/role', ErrorCode::ImmutableField);
        }

        if ($requestedLookup !== $stored->addressLookup) {
            $errors[] = new FieldError('/invitee_email', ErrorCode::ImmutableField);
        }

        return $errors;
    }

    /**
     * The invitation after it closes: the closed status, `deleted_at` set,
     * version + 1, the address and lookup value erased, the responder kept.
     * The change_seq is the repository's to stamp.
     *
     * @throws LogicException when the invitation is not pending or the status is not a closed one
     */
    public static function closed(InvitationRecord $stored, InvitationStatus $to, DateTimeImmutable $at, ?string $responderUserId = null): InvitationRecord
    {
        if (! $stored->status->canBecome($to)) {
            throw new LogicException("An invitation cannot go from {$stored->status->value} to {$to->value}.");
        }

        return new InvitationRecord(
            id: $stored->id,
            walletId: $stored->walletId,
            invitedBy: $stored->invitedBy,
            role: $stored->role,
            channel: $stored->channel,
            addressSealed: null,
            addressLookup: null,
            tokenHash: $stored->tokenHash,
            status: $to,
            expiresAt: $stored->expiresAt,
            responderUserId: $responderUserId,
            version: $stored->version + 1,
            changeSeq: $stored->changeSeq,
            createdAt: $stored->createdAt,
            updatedAt: $at,
            deletedAt: $at,
        );
    }
}
