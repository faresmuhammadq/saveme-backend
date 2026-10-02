<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Budget start day history (spec 015, FR-007, research R-3): the dated list
 * behind a profile's current `budget_period_start_day`, so a Budget Period
 * begun under an earlier day keeps its dates after a change. Server-internal,
 * never on the wire. The first entry has no `applies_from`; at most one per
 * person. Existing profiles with a pending or applied change are backfilled
 * with the prior default day 1 and the current day (pre-launch data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_start_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->smallInteger('start_day');
            $table->date('applies_from')->nullable();
            $table->timestampTz('created_at', 6);

            $table->unique(['user_id', 'applies_from']);
        });

        DB::statement('ALTER TABLE budget_start_days ADD CONSTRAINT budget_start_days_start_day_check CHECK (start_day BETWEEN 1 AND 28)');
        DB::statement('CREATE UNIQUE INDEX budget_start_days_first_unique ON budget_start_days (user_id) WHERE applies_from IS NULL');

        $now = now()->format('Y-m-d H:i:s.uP');

        DB::table('profiles')
            ->whereNotNull('budget_period_start_day_from')
            ->orderBy('id')
            ->each(function (object $profile) use ($now): void {
                DB::table('budget_start_days')->insert([
                    ['id' => (string) Str::uuid7(), 'user_id' => $profile->user_id, 'start_day' => 1, 'applies_from' => null, 'created_at' => $now],
                    ['id' => (string) Str::uuid7(), 'user_id' => $profile->user_id, 'start_day' => $profile->budget_period_start_day, 'applies_from' => $profile->budget_period_start_day_from, 'created_at' => $now],
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_start_days');
    }
};
