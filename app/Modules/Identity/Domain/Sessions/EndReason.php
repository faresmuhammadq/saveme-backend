<?php

namespace App\Modules\Identity\Domain\Sessions;

/**
 * Why a session ended (FR-026). Kept on the row so the audit trail and the
 * owner's list can say what happened.
 */
enum EndReason: string
{
    case SignedOut = 'signed_out';
    case EndedByOwner = 'ended_by_owner';
    case Replaced = 'replaced';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case Expired = 'expired';
}
