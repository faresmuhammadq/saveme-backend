<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Recurring Rule (glossary, spec 014, ADR-0018): wallet scope, syncable,
 * created by a client with its own UUIDv7. wallet_id is the scope key (the
 * source, for a transfer). created_by carries no foreign key: users belong
 * to Identity (P-02). amount_minor is a positive integer count of minor
 * units (P-03). auto_confirm_from is internal and never on the wire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->string('preset', 16);
            $table->string('status', 8);
            $table->string('kind', 8);
            $table->bigInteger('amount_minor');
            $table->string('amount_basis', 16);
            $table->char('currency', 3);
            $table->uuid('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories');
            $table->uuid('counter_wallet_id')->nullable();
            $table->foreign('counter_wallet_id')->references('id')->on('wallets');
            $table->string('description', 100)->nullable();
            $table->string('memo', 1000)->nullable();
            $table->string('counterparty', 100)->nullable();
            $table->boolean('auto_confirm');
            $table->date('auto_confirm_from');
            $table->string('frequency', 8);
            $table->smallInteger('interval');
            $table->smallInteger('anchor_day')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->integer('occurrence_count')->nullable();
            $table->integer('payout_round')->nullable();
            $table->uuid('payout_category_id')->nullable();
            $table->foreign('payout_category_id')->references('id')->on('categories');
            $table->string('timezone', 64);
            $table->uuid('created_by');
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_preset_check CHECK (preset IN ('custom', 'gameya', 'installment'))");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_status_check CHECK (status IN ('active', 'paused', 'ended'))");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_kind_check CHECK (kind IN ('income', 'expense', 'transfer'))");
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_amount_check CHECK (amount_minor > 0 AND amount_minor <= 9007199254740991)');
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_amount_basis_check CHECK (amount_basis IN ('per_occurrence', 'total'))");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_category_shape_check CHECK ((kind = 'transfer') = (category_id IS NULL))");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_counter_shape_check CHECK ((kind = 'transfer') = (counter_wallet_id IS NOT NULL))");
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_counter_differs_check CHECK (counter_wallet_id IS NULL OR counter_wallet_id <> wallet_id)');
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_description_check CHECK (description IS NULL OR btrim(description) <> '')");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_memo_check CHECK (memo IS NULL OR btrim(memo) <> '')");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_counterparty_check CHECK (counterparty IS NULL OR btrim(counterparty) <> '')");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_frequency_check CHECK (frequency IN ('daily', 'weekly', 'monthly', 'yearly'))");
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_interval_check CHECK ("interval" BETWEEN 1 AND 365)');
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_anchor_day_check CHECK (anchor_day IS NULL OR anchor_day BETWEEN 1 AND 31)');
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_anchor_shape_check CHECK ((frequency IN ('daily', 'weekly')) = (anchor_day IS NULL))");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_start_date_check CHECK (start_date BETWEEN '1900-01-01' AND '2999-12-31')");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_end_date_check CHECK (end_date IS NULL OR (end_date >= start_date AND end_date <= '2999-12-31'))");
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_count_check CHECK (occurrence_count IS NULL OR occurrence_count BETWEEN 1 AND 1000)');
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_one_end_check CHECK (NOT (end_date IS NOT NULL AND occurrence_count IS NOT NULL))');
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_payout_round_shape_check CHECK ((preset = 'gameya') = (payout_round IS NOT NULL))");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_payout_category_shape_check CHECK ((preset = 'gameya') = (payout_category_id IS NOT NULL))");
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_payout_round_range_check CHECK (payout_round IS NULL OR (payout_round >= 1 AND payout_round <= occurrence_count))');
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_version_check CHECK (version >= 1)');

        DB::statement('CREATE INDEX recurring_rules_wallet_change_seq_index ON recurring_rules (wallet_id, change_seq)');
        DB::statement('CREATE INDEX recurring_rules_wallet_created_index ON recurring_rules (wallet_id, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX recurring_rules_wallet_start_date_index ON recurring_rules (wallet_id, start_date, id) WHERE deleted_at IS NULL');
        DB::statement("CREATE INDEX recurring_rules_job_index ON recurring_rules (id) WHERE deleted_at IS NULL AND status <> 'ended'");
        DB::statement('CREATE INDEX recurring_rules_category_index ON recurring_rules (category_id) WHERE category_id IS NOT NULL');
        DB::statement('CREATE INDEX recurring_rules_payout_category_index ON recurring_rules (payout_category_id) WHERE payout_category_id IS NOT NULL');
        DB::statement('CREATE INDEX recurring_rules_counter_wallet_index ON recurring_rules (counter_wallet_id) WHERE counter_wallet_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_rules');
    }
};
