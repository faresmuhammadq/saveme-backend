<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Device Session (glossary), which is also Sanctum's token record (spec 007,
 * R-2). The id is the client-generated session id; `token` holds only the
 * SHA-256 of the secret. Rows are never deleted: an ended session survives
 * for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->char('token', 64)->unique();
            $table->jsonb('abilities')->default('["*"]');
            $table->string('device_id', 64);
            $table->string('device_name', 100);
            $table->string('platform', 16);
            $table->string('app_version', 32);
            $table->string('state', 10);
            $table->string('end_reason', 20)->nullable();
            $table->timestampTz('signed_in_at', 6);
            $table->timestampTz('last_used_at', 6)->nullable();
            $table->timestampTz('ended_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();

            $table->index(['user_id', 'signed_in_at']);
        });

        DB::statement("ALTER TABLE device_sessions ADD CONSTRAINT device_sessions_platform_check CHECK (platform IN ('android'))");
        DB::statement("ALTER TABLE device_sessions ADD CONSTRAINT device_sessions_state_check CHECK (state IN ('active', 'revoked', 'expired'))");
        DB::statement("ALTER TABLE device_sessions ADD CONSTRAINT device_sessions_end_reason_check CHECK (end_reason IN ('signed_out', 'ended_by_owner', 'replaced', 'password_changed', 'password_reset', 'expired'))");
        DB::statement("ALTER TABLE device_sessions ADD CONSTRAINT device_sessions_end_check CHECK ((state = 'active') = (ended_at IS NULL AND end_reason IS NULL))");
        DB::statement("CREATE UNIQUE INDEX device_sessions_one_active_per_device ON device_sessions (user_id, device_id) WHERE state = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
