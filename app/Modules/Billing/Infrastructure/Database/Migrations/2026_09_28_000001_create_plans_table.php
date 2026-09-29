<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Plan (glossary): the catalogue as data (P-09 rule 1, ADR-0013 rule 1).
 * Features and list prices live inside the plan; prices are integer minor
 * units plus a currency (P-03). Exactly one active plan is the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->jsonb('name');
            $table->string('period_type', 10);
            $table->integer('period_days')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 10)->default('active');
            $table->integer('sort_order');
            $table->jsonb('features');
            $table->jsonb('prices')->default('[]');
            $table->timestampsTz(6);
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_period_type_check CHECK (period_type IN ('monthly', 'yearly', 'lifetime', 'custom'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_period_days_check CHECK ((period_type = 'custom') = (period_days IS NOT NULL AND period_days >= 1))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_check CHECK (status IN ('active', 'retired'))");
        DB::statement("CREATE UNIQUE INDEX plans_one_active_default ON plans ((true)) WHERE is_default AND status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
