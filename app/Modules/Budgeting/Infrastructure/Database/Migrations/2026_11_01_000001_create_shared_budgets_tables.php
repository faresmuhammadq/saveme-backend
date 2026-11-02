<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Shared Budget (glossary, ADR-0027): a spending limit and category list that
 * several people track together. Its own sync scope: `shared_budgets.change_seq`
 * is the counter every child row is stamped from, and `purged_through` the
 * scope's purge horizon. user ids and category ids carry no foreign key: they
 * belong to Identity and Wallets (P-02). Money is integer minor units (P-03).
 * An entry has no wallet column on purpose (ADR-0027 rule 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('name', 100);
            $table->char('currency', 3);
            $table->smallInteger('start_day');
            $table->string('timezone', 64);
            $table->bigInteger('limit_minor')->nullable();
            $table->timestampTz('archived_at', 6)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->bigInteger('purged_through')->default(0);
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement('ALTER TABLE shared_budgets ADD CONSTRAINT shared_budgets_start_day_check CHECK (start_day BETWEEN 1 AND 28)');
        DB::statement('ALTER TABLE shared_budgets ADD CONSTRAINT shared_budgets_limit_check CHECK (limit_minor IS NULL OR limit_minor BETWEEN 1 AND 9007199254740991)');
        DB::statement("ALTER TABLE shared_budgets ADD CONSTRAINT shared_budgets_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE shared_budgets ADD CONSTRAINT shared_budgets_version_check CHECK (version >= 1)');

        Schema::create('shared_budget_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shared_budget_id');
            $table->uuid('user_id');
            $table->string('role', 8);
            $table->boolean('read_only')->default(false);
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->bigInteger('member_change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->foreign('shared_budget_id')->references('id')->on('shared_budgets');
        });

        DB::statement("ALTER TABLE shared_budget_members ADD CONSTRAINT shared_budget_members_role_check CHECK (role IN ('owner', 'member'))");
        DB::statement('CREATE UNIQUE INDEX shared_budget_members_active_unique ON shared_budget_members (shared_budget_id, user_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX shared_budget_members_feed_index ON shared_budget_members (shared_budget_id, change_seq)');
        DB::statement('CREATE INDEX shared_budget_members_user_feed_index ON shared_budget_members (user_id, member_change_seq)');

        Schema::create('shared_budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shared_budget_id');
            $table->uuid('category_id');
            $table->bigInteger('limit_minor')->nullable();
            $table->string('name', 100)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 16)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->foreign('shared_budget_id')->references('id')->on('shared_budgets');
        });

        DB::statement('ALTER TABLE shared_budget_lines ADD CONSTRAINT shared_budget_lines_limit_check CHECK (limit_minor IS NULL OR limit_minor BETWEEN 1 AND 9007199254740991)');
        DB::statement('CREATE UNIQUE INDEX shared_budget_lines_live_unique ON shared_budget_lines (shared_budget_id, category_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX shared_budget_lines_feed_index ON shared_budget_lines (shared_budget_id, change_seq)');
        DB::statement('CREATE INDEX shared_budget_lines_category_index ON shared_budget_lines (category_id)');

        Schema::create('shared_budget_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shared_budget_id');
            $table->uuid('line_id')->nullable();
            $table->bigInteger('limit_minor')->nullable();
            $table->date('applies_from');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->foreign('shared_budget_id')->references('id')->on('shared_budgets');
        });

        DB::statement('ALTER TABLE shared_budget_limits ADD CONSTRAINT shared_budget_limits_limit_check CHECK (limit_minor IS NULL OR limit_minor BETWEEN 1 AND 9007199254740991)');
        DB::statement("CREATE UNIQUE INDEX shared_budget_limits_period_unique ON shared_budget_limits (shared_budget_id, COALESCE(line_id, '00000000-0000-0000-0000-000000000000'::uuid), applies_from)");
        DB::statement('CREATE INDEX shared_budget_limits_feed_index ON shared_budget_limits (shared_budget_id, change_seq)');

        Schema::create('shared_budget_start_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shared_budget_id');
            $table->smallInteger('start_day');
            $table->date('applies_from')->nullable();
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->foreign('shared_budget_id')->references('id')->on('shared_budgets');
        });

        DB::statement('ALTER TABLE shared_budget_start_days ADD CONSTRAINT shared_budget_start_days_day_check CHECK (start_day BETWEEN 1 AND 28)');
        DB::statement('CREATE UNIQUE INDEX shared_budget_start_days_unique ON shared_budget_start_days (shared_budget_id, applies_from)');
        DB::statement('CREATE INDEX shared_budget_start_days_feed_index ON shared_budget_start_days (shared_budget_id, change_seq)');

        Schema::create('shared_budget_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shared_budget_id');
            $table->uuid('transaction_id');
            $table->uuid('created_by');
            $table->uuid('category_id');
            $table->date('date');
            $table->bigInteger('amount_minor');
            $table->string('description', 100)->nullable();
            $table->timestampTz('detached_at', 6)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->foreign('shared_budget_id')->references('id')->on('shared_budgets');
            $table->unique(['shared_budget_id', 'transaction_id']);
        });

        DB::statement('ALTER TABLE shared_budget_entries ADD CONSTRAINT shared_budget_entries_amount_check CHECK (amount_minor BETWEEN 1 AND 9007199254740991)');
        DB::statement('CREATE INDEX shared_budget_entries_feed_index ON shared_budget_entries (shared_budget_id, change_seq)');
        DB::statement('CREATE INDEX shared_budget_entries_period_index ON shared_budget_entries (shared_budget_id, date) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX shared_budget_entries_transaction_index ON shared_budget_entries (transaction_id)');
        DB::statement('CREATE INDEX shared_budget_entries_author_index ON shared_budget_entries (shared_budget_id, created_by) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_budget_entries');
        Schema::dropIfExists('shared_budget_start_days');
        Schema::dropIfExists('shared_budget_limits');
        Schema::dropIfExists('shared_budget_lines');
        Schema::dropIfExists('shared_budget_members');
        Schema::dropIfExists('shared_budgets');
    }
};
