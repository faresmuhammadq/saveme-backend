<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Address verification and password reset links: single use, time-limited,
 * stored only as a hash (security baseline, Reset and verification tokens).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->char('secret_hash', 64)->unique();
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('used_at', 6)->nullable();
            $table->timestampTz('superseded_at', 6)->nullable();
            $table->timestampTz('created_at', 6);

            $table->index(['user_id', 'kind']);
        });

        DB::statement("ALTER TABLE identity_links ADD CONSTRAINT identity_links_kind_check CHECK (kind IN ('verify_address', 'reset_password'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_links');
    }
};
