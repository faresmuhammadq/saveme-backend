<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A transaction a confirmed occurrence produced names its rule and its
 * occurrence (spec 014, FR-024, ADR-0018 rule 5). Both are read-only on the
 * wire and set only through TransactionService::recordForOccurrence. At most
 * one transaction per occurrence, by a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('recurring_rule_id')->nullable();
            $table->foreign('recurring_rule_id')->references('id')->on('recurring_rules');
            $table->uuid('occurrence_id')->nullable();
            $table->foreign('occurrence_id')->references('id')->on('occurrences');
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_occurrence_shape_check CHECK ((recurring_rule_id IS NULL) = (occurrence_id IS NULL))');
        DB::statement('CREATE UNIQUE INDEX transactions_occurrence_unique ON transactions (occurrence_id) WHERE occurrence_id IS NOT NULL');
        DB::statement('CREATE INDEX transactions_recurring_rule_index ON transactions (recurring_rule_id) WHERE recurring_rule_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_recurring_rule_index');
        DB::statement('DROP INDEX IF EXISTS transactions_occurrence_unique');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_occurrence_shape_check');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['occurrence_id']);
            $table->dropForeign(['recurring_rule_id']);
            $table->dropColumn(['occurrence_id', 'recurring_rule_id']);
        });
    }
};
