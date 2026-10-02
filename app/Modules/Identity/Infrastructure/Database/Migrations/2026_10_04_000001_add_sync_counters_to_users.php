<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The user scope row (spec 013, R-5, R-10): change_seq is the user scope
 * counter every user-scoped write stamps from under the row lock (ADR-0008
 * rule 2), and purged_change_seq is the scope's purge horizon. The Sync
 * switch migration backfills the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('change_seq')->default(0);
            $table->bigInteger('purged_change_seq')->default(0);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->index(['user_id', 'change_seq']);
        });

        Schema::table('device_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'change_seq']);
        });
    }

    public function down(): void
    {
        Schema::table('device_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'change_seq']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'change_seq']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['change_seq', 'purged_change_seq']);
        });
    }
};
