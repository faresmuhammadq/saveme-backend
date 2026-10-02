<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Posting (glossary, ADR-0012): the ledger leg. Exactly one of a wallet or
 * a category (rule 3); the two postings of a live transaction sum to zero.
 * Server-derived, never exposed and never synced, so no timestamps and no
 * sync members. Removed with a deleted transaction, re-derived on restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
            $table->uuid('wallet_id')->nullable();
            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->uuid('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
        });

        DB::statement('ALTER TABLE postings ADD CONSTRAINT postings_target_check CHECK ((wallet_id IS NULL) <> (category_id IS NULL))');
        DB::statement('ALTER TABLE postings ADD CONSTRAINT postings_amount_check CHECK (amount_minor <> 0)');

        DB::statement('CREATE INDEX postings_wallet_index ON postings (wallet_id)');
        DB::statement('CREATE INDEX postings_category_index ON postings (category_id)');
        DB::statement('CREATE INDEX postings_transaction_index ON postings (transaction_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
