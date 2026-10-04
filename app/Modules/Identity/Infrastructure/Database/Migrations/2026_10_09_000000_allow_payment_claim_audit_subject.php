<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * InstaPay claims (spec 020, R-9): an audit entry can be about a payment
 * claim, and Billing writes a new `claim_reviewed` event. Identity owns the
 * table, so Identity owns the constraints. The append-only trigger is
 * untouched.
 */
return new class extends Migration
{
    private const EVENTS = "'sign_in_succeeded', 'sign_in_failed', 'lockout_applied', 'session_issued', 'session_revoked', 'password_changed', 'address_change_requested', 'address_verified', 'membership_granted', 'membership_revoked', 'role_changed', 'entitlement_changed', 'export_requested', 'export_delivered', 'deletion_requested', 'deletion_cancelled', 'deletion_completed', 'admin_action', 'audit_read'";

    public function up(): void
    {
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_subject_type_check');
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_subject_type_check CHECK (subject_type IN ('user', 'wallet', 'session', 'subscription', 'operator', 'payment_claim'))");
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_event_check');
        DB::statement('ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_event_check CHECK (event IN ('.self::EVENTS.", 'claim_reviewed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_event_check');
        DB::statement('ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_event_check CHECK (event IN ('.self::EVENTS.'))');
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_subject_type_check');
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_subject_type_check CHECK (subject_type IN ('user', 'wallet', 'session', 'subscription', 'operator'))");
    }
};
