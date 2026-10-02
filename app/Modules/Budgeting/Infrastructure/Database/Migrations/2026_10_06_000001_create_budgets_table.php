<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Budget (glossary, spec 015): one person's monthly limit for one expense
 * category, or all expenses when category_id is null, in one currency. User
 * scope: stamped from the owner's counter. user_id and category_id carry no
 * foreign key: users belong to Identity and categories to Wallets (P-02).
 * Money is integer minor units (P-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('category_id')->nullable();
            $table->string('period_type', 8);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('archived_at', 6)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_period_type_check CHECK (period_type IN ('monthly'))");
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_amount_check CHECK (amount_minor BETWEEN 1 AND 9007199254740991)');
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_change_seq_check CHECK (change_seq >= 1)');

        DB::statement('CREATE INDEX budgets_user_change_seq_index ON budgets (user_id, change_seq)');
        DB::statement('CREATE INDEX budgets_user_live_index ON budgets (user_id, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX budgets_category_index ON budgets (category_id) WHERE category_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
