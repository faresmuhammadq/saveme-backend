<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Shared Budget a transaction or a recurring rule names (ADR-0027 rule 4;
 * an occurrence takes its rule's). Nullable and without a foreign key: shared budgets belong
 * to Budgeting (P-02). Only a transaction's author may change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['transactions', 'recurring_rules'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('shared_budget_id')->nullable();
            });
        }

        DB::statement('CREATE INDEX transactions_shared_budget_index ON transactions (shared_budget_id) WHERE shared_budget_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_shared_budget_index');

        foreach (['recurring_rules', 'transactions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('shared_budget_id');
            });
        }
    }
};
