<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * User (glossary): credentials and lifecycle only. The address is sealed with
 * the person's data key and found through its keyed lookup value; no readable
 * address is stored (security baseline; spec 007 data-model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('email_ciphertext');
            $table->char('email_lookup', 64)->unique();
            $table->text('password_hash');
            $table->timestampTz('email_verified_at', 6)->nullable();
            $table->string('state', 20)->default('active');
            $table->timestampTz('deletion_requested_at', 6)->nullable();
            $table->timestampTz('deletion_scheduled_for', 6)->nullable();
            $table->timestampsTz(6);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state IN ('active', 'pending_deletion', 'purged'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_deletion_schedule_check CHECK ((state = 'pending_deletion') = (deletion_requested_at IS NOT NULL AND deletion_scheduled_for IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
