<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Re-enrolment Request (glossary; spec 018, data-model): at most one open
 * request per operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_reenrolment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operator_id')->constrained('operator_accounts');
            $table->string('state', 12);
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('closed_at', 6)->nullable();
            $table->uuid('closed_by')->nullable();
        });

        DB::statement("ALTER TABLE operator_reenrolment_requests ADD CONSTRAINT operator_reenrolment_requests_state_check CHECK (state IN ('open', 'completed', 'declined', 'expired', 'cancelled'))");
        DB::statement("ALTER TABLE operator_reenrolment_requests ADD CONSTRAINT operator_reenrolment_requests_closed_check CHECK ((state = 'open') = (closed_at IS NULL))");
        DB::statement("CREATE UNIQUE INDEX operator_reenrolment_requests_one_open ON operator_reenrolment_requests (operator_id) WHERE state = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_reenrolment_requests');
    }
};
