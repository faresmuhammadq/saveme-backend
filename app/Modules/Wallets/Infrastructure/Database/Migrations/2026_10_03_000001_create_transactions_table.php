<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Transaction (glossary, spec 012, ADR-0015): wallet scope, syncable. A
 * transfer is one row: wallet_id is the source, counter_wallet_id the
 * destination, and counter_change_seq is the stamp from the destination's
 * counter (never on the wire). created_by carries no foreign key: users
 * belong to Identity (P-02). amount_minor is a positive integer count of
 * minor units; the direction comes from kind (P-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->string('kind', 8);
            $table->date('date');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->uuid('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories');
            $table->uuid('counter_wallet_id')->nullable();
            $table->foreign('counter_wallet_id')->references('id')->on('wallets');
            $table->string('description', 100)->nullable();
            $table->string('memo', 1000)->nullable();
            $table->string('counterparty', 100)->nullable();
            $table->boolean('cleared')->default(false);
            $table->uuid('created_by');
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->bigInteger('counter_change_seq')->nullable();
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_check CHECK (kind IN ('income', 'expense', 'transfer'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_date_check CHECK (date BETWEEN '1900-01-01' AND '2999-12-31')");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_check CHECK (amount_minor > 0 AND amount_minor <= 9007199254740991)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_category_shape_check CHECK ((kind = 'transfer') = (category_id IS NULL))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_counter_shape_check CHECK ((kind = 'transfer') = (counter_wallet_id IS NOT NULL))");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_counter_differs_check CHECK (counter_wallet_id IS NULL OR counter_wallet_id <> wallet_id)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_description_check CHECK (description IS NULL OR btrim(description) <> '')");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_memo_check CHECK (memo IS NULL OR btrim(memo) <> '')");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_counterparty_check CHECK (counterparty IS NULL OR btrim(counterparty) <> '')");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_counter_change_seq_check CHECK ((counter_wallet_id IS NULL) = (counter_change_seq IS NULL))');

        DB::statement('CREATE INDEX transactions_wallet_date_index ON transactions (wallet_id, date DESC, id)');
        DB::statement('CREATE INDEX transactions_wallet_created_index ON transactions (wallet_id, created_at, id)');
        DB::statement('CREATE INDEX transactions_counter_wallet_date_index ON transactions (counter_wallet_id, date DESC, id) WHERE counter_wallet_id IS NOT NULL');
        DB::statement('CREATE INDEX transactions_wallet_change_seq_index ON transactions (wallet_id, change_seq)');
        DB::statement('CREATE INDEX transactions_counter_wallet_change_seq_index ON transactions (counter_wallet_id, counter_change_seq) WHERE counter_wallet_id IS NOT NULL');
        DB::statement('CREATE INDEX transactions_category_index ON transactions (category_id)');
        DB::statement('CREATE INDEX transactions_created_by_live_index ON transactions (created_by) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
