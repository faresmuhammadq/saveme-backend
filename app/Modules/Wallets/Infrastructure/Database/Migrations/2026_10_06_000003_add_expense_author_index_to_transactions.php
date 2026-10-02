<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Budgeting's spending query (spec 015, R-1): a person's own live expenses by
 * date. Partial, so it holds only the rows the query reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX transactions_expense_author_date_index ON transactions (created_by, date) WHERE deleted_at IS NULL AND kind = 'expense'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_expense_author_date_index');
    }
};
