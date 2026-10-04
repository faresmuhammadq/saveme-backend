<?php

namespace App\Modules\Admin\Domain\Operators;

/**
 * The closed list of `admin_action` actions operator sign-in writes
 * (spec 018, R-13).
 */
enum AdminAction: string
{
    case OperatorCreated = 'operator_created';
    case OperatorDeactivated = 'operator_deactivated';
    case TemporaryPasswordIssued = 'temporary_password_issued';
    case EnrolmentCompleted = 'enrolment_completed';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case AuthenticatorReplaced = 'authenticator_replaced';
    case ReenrolmentRequested = 'reenrolment_requested';
    case ReenrolmentApproved = 'reenrolment_approved';
    case ReenrolmentDeclined = 'reenrolment_declined';
    case ReenrolmentCompleted = 'reenrolment_completed';
    case ReenrolmentExpired = 'reenrolment_expired';
    case ReenrolmentCancelled = 'reenrolment_cancelled';
}
