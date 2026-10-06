<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Notification Preferences (glossary, spec 023): one row per person, written
 * by the first save. A missing row means the defaults at version 1. Quiet
 * hours are wall-clock minutes since midnight on a quarter hour (P-12): no
 * time-zone-bearing type, applied in the profile timezone at send time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->boolean('recurring_due')->default(true);
            $table->boolean('budget_exceeded')->default(true);
            $table->boolean('sharing')->default(true);
            $table->boolean('billing')->default(true);
            $table->boolean('export_ready')->default(true);
            $table->boolean('deletion_reminder')->default(true);
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->smallInteger('quiet_hours_start')->default(1320);
            $table->smallInteger('quiet_hours_end')->default(480);
            $table->integer('version');
            $table->timestampsTz(6);
        });

        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_start_check CHECK (quiet_hours_start >= 0 AND quiet_hours_start <= 1425 AND quiet_hours_start % 15 = 0)');
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_end_check CHECK (quiet_hours_end >= 0 AND quiet_hours_end <= 1425 AND quiet_hours_end % 15 = 0)');
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_distinct_check CHECK (quiet_hours_start <> quiet_hours_end)');
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_version_check CHECK (version >= 2)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
