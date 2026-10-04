<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Re-enrolment approvals (spec 018, data-model). The reason lives in the
 * `admin_action` audit entry, not here: one copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_reenrolment_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('operator_reenrolment_requests')->cascadeOnDelete();
            $table->foreignUuid('approver_id')->constrained('operator_accounts');
            $table->timestampTz('approved_at', 6);

            $table->unique(['request_id', 'approver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_reenrolment_approvals');
    }
};
