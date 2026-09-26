<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Consecutive sign-in failures per address lookup value, registered or not,
 * so the lock never discloses whether an account exists (spec 007, FR-041).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_in_failures', function (Blueprint $table) {
            $table->char('lookup', 64)->primary();
            $table->integer('consecutive_failures');
            $table->timestampTz('locked_until', 6)->nullable();
            $table->timestampTz('last_failed_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_failures');
    }
};
