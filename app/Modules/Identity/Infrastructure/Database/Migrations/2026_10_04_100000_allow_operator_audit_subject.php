<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Operator sign-in (spec 018, R-13): an audit entry can be about an operator.
 * Identity owns the table, so Identity owns the constraint. The append-only
 * trigger is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_subject_type_check');
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_subject_type_check CHECK (subject_type IN ('user', 'wallet', 'session', 'subscription', 'operator'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_entries DROP CONSTRAINT audit_entries_subject_type_check');
        DB::statement("ALTER TABLE audit_entries ADD CONSTRAINT audit_entries_subject_type_check CHECK (subject_type IN ('user', 'wallet', 'session', 'subscription'))");
    }
};
