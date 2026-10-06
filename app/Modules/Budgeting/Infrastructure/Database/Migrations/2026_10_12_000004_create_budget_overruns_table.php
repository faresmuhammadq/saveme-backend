<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Budget Overrun (glossary, spec 023, ADR-0025): the record that one budget's
 * spending went strictly above its limit in one Budget Period, kept so the
 * owner is told once. Progress itself stays computed on read (ADR-0019); this
 * stores only the fact that a period crossed its limit. user_id carries no
 * foreign key (P-02). Removed with its budget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_overruns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('budget_id');
            $table->foreign('budget_id')->references('id')->on('budgets')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->date('period_start');
            $table->smallInteger('threshold');
            $table->timestampTz('crossed_at', 6);

            $table->unique(['budget_id', 'period_start', 'threshold']);
        });

        DB::statement('ALTER TABLE budget_overruns ADD CONSTRAINT budget_overruns_threshold_check CHECK (threshold = 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_overruns');
    }
};
