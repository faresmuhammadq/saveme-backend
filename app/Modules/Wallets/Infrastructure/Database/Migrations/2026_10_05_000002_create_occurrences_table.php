<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Occurrence (glossary, spec 014, ADR-0018): wallet scope, syncable, created
 * only by the server. The occurrence key (recurring_rule_id, scheduled_date,
 * is_payout) is unique over live and tombstoned rows, so materialising twice
 * can never duplicate. transaction_id carries no foreign key: the transaction
 * is inserted in the same unit of work after the occurrence row is locked;
 * the link is enforced by the unique indexes on both sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->uuid('recurring_rule_id');
            $table->foreign('recurring_rule_id')->references('id')->on('recurring_rules');
            $table->integer('ordinal');
            $table->boolean('is_payout')->default(false);
            $table->date('scheduled_date');
            $table->date('date');
            $table->string('status', 9);
            $table->string('kind', 8);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->uuid('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories');
            $table->uuid('counter_wallet_id')->nullable();
            $table->foreign('counter_wallet_id')->references('id')->on('wallets');
            $table->string('description', 100)->nullable();
            $table->string('memo', 1000)->nullable();
            $table->string('counterparty', 100)->nullable();
            $table->boolean('edited')->default(false);
            $table->uuid('transaction_id')->nullable();
            $table->string('auto_confirm_refusal', 32)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_status_check CHECK (status IN ('scheduled', 'confirmed', 'skipped'))");
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_kind_check CHECK (kind IN ('income', 'expense', 'transfer'))");
        DB::statement('ALTER TABLE occurrences ADD CONSTRAINT occurrences_amount_check CHECK (amount_minor > 0 AND amount_minor <= 9007199254740991)');
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_date_check CHECK (date BETWEEN '1900-01-01' AND '2999-12-31')");
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_scheduled_date_check CHECK (scheduled_date BETWEEN '1900-01-01' AND '2999-12-31')");
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_transaction_shape_check CHECK ((status = 'confirmed') = (transaction_id IS NOT NULL))");
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_refusal_check CHECK (auto_confirm_refusal IS NULL OR auto_confirm_refusal IN ('not_permitted', 'counter_wallet_unavailable', 'currency_mismatch'))");
        DB::statement('ALTER TABLE occurrences ADD CONSTRAINT occurrences_ordinal_check CHECK (ordinal >= 1)');
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_description_check CHECK (description IS NULL OR btrim(description) <> '')");
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_memo_check CHECK (memo IS NULL OR btrim(memo) <> '')");
        DB::statement("ALTER TABLE occurrences ADD CONSTRAINT occurrences_counterparty_check CHECK (counterparty IS NULL OR btrim(counterparty) <> '')");
        DB::statement('ALTER TABLE occurrences ADD CONSTRAINT occurrences_version_check CHECK (version >= 1)');

        DB::statement('CREATE UNIQUE INDEX occurrences_key_unique ON occurrences (recurring_rule_id, scheduled_date, is_payout)');
        DB::statement('CREATE UNIQUE INDEX occurrences_transaction_unique ON occurrences (transaction_id) WHERE transaction_id IS NOT NULL');
        DB::statement('CREATE INDEX occurrences_wallet_change_seq_index ON occurrences (wallet_id, change_seq)');
        DB::statement('CREATE INDEX occurrences_wallet_date_index ON occurrences (wallet_id, date, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX occurrences_wallet_created_index ON occurrences (wallet_id, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX occurrences_rule_status_index ON occurrences (recurring_rule_id, status) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX occurrences_category_index ON occurrences (category_id) WHERE category_id IS NOT NULL');
        DB::statement('CREATE INDEX occurrences_counter_wallet_index ON occurrences (counter_wallet_id) WHERE counter_wallet_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrences');
    }
};
