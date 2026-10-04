<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Consecutive operator sign-in failures per email lookup value, registered or
 * not (spec 018, R-9). Same shape as Identity's, separate table (FR-052).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_sign_in_failures', function (Blueprint $table) {
            $table->char('lookup', 64)->primary();
            $table->integer('consecutive_failures');
            $table->timestampTz('locked_until', 6)->nullable();
            $table->timestampTz('last_failed_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_sign_in_failures');
    }
};
