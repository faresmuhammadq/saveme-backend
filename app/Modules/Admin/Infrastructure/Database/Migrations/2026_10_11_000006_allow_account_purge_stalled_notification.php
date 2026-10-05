<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Spec 022, FR-063: an operator is told when an account purge has not
 * finished after 24 hours. A second notification kind, identifiers only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE operator_notifications DROP CONSTRAINT operator_notifications_kind_check');
        DB::statement("ALTER TABLE operator_notifications ADD CONSTRAINT operator_notifications_kind_check CHECK (kind IN ('deletion_cancelled_by_sign_in', 'account_purge_stalled'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM operator_notifications WHERE kind = 'account_purge_stalled'");
        DB::statement('ALTER TABLE operator_notifications DROP CONSTRAINT operator_notifications_kind_check');
        DB::statement("ALTER TABLE operator_notifications ADD CONSTRAINT operator_notifications_kind_check CHECK (kind IN ('deletion_cancelled_by_sign_in'))");
    }
};
