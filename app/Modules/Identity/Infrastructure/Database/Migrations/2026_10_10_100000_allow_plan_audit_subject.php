<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Back-office operations (spec 021, data-model): an audit entry can be about
 * a plan, and the audit log viewer reads by time and by actor, so the table
 * gains two indexes. Identity owns the table, so Identity owns the
 * constraint. The append-only trigger is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_subject_type_check');
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_subject_type_check CHECK (subject_type IN ('user', 'wallet', 'session', 'subscription', 'operator', 'payment_claim', 'plan'))");
        DB::statement('CREATE INDEX audit_entries_occurred_at_id_index ON audit_entries (occurred_at, id)');
        DB::statement('CREATE INDEX audit_entries_actor_index ON audit_entries (actor_id, occurred_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_entries_actor_index');
        DB::statement('DROP INDEX IF EXISTS audit_entries_occurred_at_id_index');
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_subject_type_check');
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_subject_type_check CHECK (subject_type IN ('user', 'wallet', 'session', 'subscription', 'operator', 'payment_claim'))");
    }
};
