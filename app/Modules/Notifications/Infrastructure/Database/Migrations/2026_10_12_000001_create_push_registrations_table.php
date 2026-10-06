<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Push Registration (glossary, spec 023, ADR-0025): where one device session
 * can be reached. user_id and session_id carry no foreign key: both belong to
 * Identity (P-02). The token is sealed (XChaCha20-Poly1305, Notifications'
 * keyring) with a keyed lookup value beside it, so one token belongs to one
 * registration without opening any. Hard-deleted when it ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('session_id')->unique();
            $table->text('token_sealed');
            $table->char('token_lookup', 64)->unique();
            $table->timestampTz('registered_at', 6);
            $table->timestampTz('refreshed_at', 6);
            $table->timestampsTz(6);

            $table->index('user_id');
            $table->index('refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_registrations');
    }
};
