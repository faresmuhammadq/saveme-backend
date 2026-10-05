<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Export & deletion (spec 022, data-model): the `purging` state, and the
 * `purged` husk that holds no identity attribute. The address, its lookup
 * value and the password hash become nullable, required unless purged, so a
 * purged address can register again. A session may end as `account_deleted`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('deletion_reminded_at', 6)->nullable();
            $table->timestampTz('purged_at', 6)->nullable();
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email_ciphertext DROP NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN email_lookup DROP NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN password_hash DROP NOT NULL');

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_state_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_schedule_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state IN ('active', 'pending_deletion', 'purging', 'purged'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_deletion_schedule_check CHECK ((state IN ('pending_deletion', 'purging')) = (deletion_requested_at IS NOT NULL AND deletion_scheduled_for IS NOT NULL))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_purged_at_check CHECK ((state = \'purged\') = (purged_at IS NOT NULL))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_identity_check CHECK (state = 'purged' OR (email_ciphertext IS NOT NULL AND email_lookup IS NOT NULL AND password_hash IS NOT NULL))");

        DB::statement('ALTER TABLE device_sessions DROP CONSTRAINT device_sessions_end_reason_check');
        DB::statement("ALTER TABLE device_sessions ADD CONSTRAINT device_sessions_end_reason_check CHECK (end_reason IN ('signed_out', 'ended_by_owner', 'replaced', 'password_changed', 'password_reset', 'expired', 'account_deleted'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE device_sessions DROP CONSTRAINT device_sessions_end_reason_check');
        DB::statement("ALTER TABLE device_sessions ADD CONSTRAINT device_sessions_end_reason_check CHECK (end_reason IN ('signed_out', 'ended_by_owner', 'replaced', 'password_changed', 'password_reset', 'expired'))");

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_identity_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_purged_at_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_schedule_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_state_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state IN ('active', 'pending_deletion', 'purged'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_deletion_schedule_check CHECK ((state = 'pending_deletion') = (deletion_requested_at IS NOT NULL AND deletion_scheduled_for IS NOT NULL))");

        DB::statement('ALTER TABLE users ALTER COLUMN password_hash SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN email_lookup SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN email_ciphertext SET NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deletion_reminded_at', 'purged_at']);
        });
    }
};
