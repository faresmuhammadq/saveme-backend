<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Reports (spec 019, research R-3): a wallet's leg sums for the summary,
 * balances and trend read (wallet_id, transaction_id, amount_minor) from this
 * partial covering index and probe transactions by primary key. The existing
 * postings_wallet_index stays for its other readers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX postings_wallet_leg_index ON postings (wallet_id, transaction_id) INCLUDE (amount_minor) WHERE wallet_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS postings_wallet_leg_index');
    }
};
