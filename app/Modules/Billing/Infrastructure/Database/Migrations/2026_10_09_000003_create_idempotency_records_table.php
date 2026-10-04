<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Idempotency Record (glossary; spec 020, R-2): the stored answer for one
 * Idempotency-Key, in a scope of one user or the anonymous scope, for 24
 * hours. The body holds the answer only; neither answer carries a secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 48);
            $table->string('key', 255);
            $table->char('fingerprint', 64);
            $table->string('state', 12);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('expires_at', 6);

            $table->unique(['scope', 'key']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_state_check CHECK (state IN ('in_progress', 'completed'))");
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_completed_check CHECK ((state = 'completed') = (response_status IS NOT NULL AND response_body IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
