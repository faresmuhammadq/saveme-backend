<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Recovery Code (glossary; spec 018, data-model): one keyed hash per code,
 * ten per set, used once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operator_id')->constrained('operator_accounts')->cascadeOnDelete();
            $table->uuid('set_id');
            $table->char('code_hash', 64);
            $table->timestampTz('used_at', 6)->nullable();
            $table->timestampTz('created_at', 6);

            $table->unique(['operator_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_recovery_codes');
    }
};
