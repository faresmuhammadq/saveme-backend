<?php

namespace App\Modules\Admin\Domain\Operators;

/**
 * The closed list of `admin_action` actions operator sign-in writes
 * (spec 018, R-13), and the two reads of a payment claim (spec 020, FR-014a,
 * FR-045), and the account and plan acts of spec 021.
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
    case ClaimReceiptViewed = 'claim_receipt_viewed';
    case ClaimAddressRead = 'claim_address_read';
    case AccountViewed = 'account_viewed';
    case AccountLookupMissed = 'account_lookup_missed';
    case PlanUpdated = 'plan_updated';
    case PlanRetired = 'plan_retired';
}
