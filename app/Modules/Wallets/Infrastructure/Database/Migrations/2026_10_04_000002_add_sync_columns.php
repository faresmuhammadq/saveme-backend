<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sync columns of the Wallets tables (spec 013, R-6, R-8, R-10): the wallet
 * scope's purge horizon, a membership's position on its member's user feed
 * (nullable until the Sync switch migration stamps it, then NOT NULL), and
 * the index that serves a person's categories on the user feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->bigInteger('purged_change_seq')->default(0);
        });

        Schema::table('wallet_members', function (Blueprint $table) {
            $table->bigInteger('member_change_seq')->nullable();
            $table->index(['user_id', 'member_change_seq'], 'wallet_members_user_member_change_seq_index');
        });

        DB::statement('CREATE INDEX categories_user_change_seq_index ON categories (user_id, change_seq) WHERE user_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS categories_user_change_seq_index');

        Schema::table('wallet_members', function (Blueprint $table) {
            $table->dropIndex('wallet_members_user_member_change_seq_index');
            $table->dropColumn('member_change_seq');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('purged_change_seq');
        });
    }
};
