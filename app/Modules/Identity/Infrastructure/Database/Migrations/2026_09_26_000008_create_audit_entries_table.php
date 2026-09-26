<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The single audit log (security baseline, The audit entry). Append-only is
 * enforced by the database: a trigger refuses any UPDATE, and any DELETE of an
 * entry younger than its 400-day retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestampTz('occurred_at', 6);
            $table->string('event', 40);
            $table->string('actor_type', 10);
            $table->uuid('actor_id')->nullable();
            $table->string('subject_type', 15);
            $table->uuid('subject_id')->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('origin', 64)->nullable();
            $table->jsonb('detail');

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });

        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_event_check CHECK (event IN ('sign_in_succeeded', 'sign_in_failed', 'lockout_applied', 'session_issued', 'session_revoked', 'password_changed', 'address_change_requested', 'address_verified', 'membership_granted', 'membership_revoked', 'role_changed', 'entitlement_changed', 'export_requested', 'export_delivered', 'deletion_requested', 'deletion_cancelled', 'deletion_completed', 'admin_action', 'audit_read'))");
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_actor_type_check CHECK (actor_type IN ('user', 'operator', 'system'))");
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_subject_type_check CHECK (subject_type IN ('user', 'wallet', 'session', 'subscription'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_entries_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'audit_entries is append-only: an entry is never edited';
                END IF;
                IF OLD.occurred_at > now() - interval '400 days' THEN
                    RAISE EXCEPTION 'audit_entries is append-only: an entry is kept 400 days';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_entries_append_only
                BEFORE UPDATE OR DELETE ON audit_entries
                FOR EACH ROW EXECUTE FUNCTION audit_entries_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_entries_append_only()');
    }
};
