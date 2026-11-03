<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Balance adjustments (ADR-0028): two more transaction kinds, with no category
 * and no counter wallet on the wire. Their other posting goes to one reserved
 * platform category that lists, feeds, exports and pickers never show
 * (`categories.internal`). It is created here, not by the seeder, so a posting
 * can never fail for want of seed data.
 */
return new class extends Migration
{
    private const CATEGORY_ID = '00000000-0000-7000-8000-00000000ad01';

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('internal')->default(false);
        });

        DB::statement('ALTER TABLE transactions ALTER COLUMN kind TYPE varchar(16)');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_kind_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_check CHECK (kind IN ('income', 'expense', 'transfer', 'adjustment_in', 'adjustment_out'))");
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_shape_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_category_shape_check CHECK ((kind IN ('income', 'expense')) = (category_id IS NOT NULL))");
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_counter_shape_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_counter_shape_check CHECK ((kind = 'transfer') = (counter_wallet_id IS NOT NULL))");

        DB::table('categories')->insert([
            'id' => self::CATEGORY_ID,
            'user_id' => null,
            'system_key' => 'balance_adjustment',
            'kind' => 'expense',
            'name' => null,
            'names' => json_encode(['en' => 'Balance adjustment', 'ar' => 'تعديل الرصيد']),
            'icon' => 'dots',
            'color' => '#757575',
            'position' => 1000,
            'parent_id' => null,
            'archived_at' => null,
            'version' => 1,
            'change_seq' => null,
            'internal' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    public function down(): void
    {
        DB::table('postings')->where('category_id', self::CATEGORY_ID)->delete();
        DB::table('transactions')->whereIn('kind', ['adjustment_in', 'adjustment_out'])->delete();
        DB::table('categories')->where('id', self::CATEGORY_ID)->delete();

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_shape_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_category_shape_check CHECK ((kind = 'transfer') = (category_id IS NULL))");
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_kind_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_check CHECK (kind IN ('income', 'expense', 'transfer'))");
        DB::statement('ALTER TABLE transactions ALTER COLUMN kind TYPE varchar(8)');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('internal');
        });
    }
};
