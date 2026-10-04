<?php

namespace App\Modules\Identity\Domain\Audit;

/**
 * The fixed audit vocabulary (security baseline, What is recorded). A module
 * never invents a local event name; a genuinely new event is added to the
 * baseline's table and here in the same change.
 */
enum AuditEvent: string
{
    case SignInSucceeded = 'sign_in_succeeded';
    case SignInFailed = 'sign_in_failed';
    case LockoutApplied = 'lockout_applied';
    case SessionIssued = 'session_issued';
    case SessionRevoked = 'session_revoked';
    case PasswordChanged = 'password_changed';
    case AddressChangeRequested = 'address_change_requested';
    case AddressVerified = 'address_verified';
    case MembershipGranted = 'membership_granted';
    case MembershipRevoked = 'membership_revoked';
    case RoleChanged = 'role_changed';
    case EntitlementChanged = 'entitlement_changed';
    case ExportRequested = 'export_requested';
    case ExportDelivered = 'export_delivered';
    case DeletionRequested = 'deletion_requested';
    case DeletionCancelled = 'deletion_cancelled';
    case DeletionCompleted = 'deletion_completed';
    case AdminAction = 'admin_action';
    case AuditRead = 'audit_read';
    case ClaimReviewed = 'claim_reviewed';
}
