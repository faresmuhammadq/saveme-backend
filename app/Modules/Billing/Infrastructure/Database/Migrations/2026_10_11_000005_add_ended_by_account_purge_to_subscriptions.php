<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Spec 022, FR-047: a purged account's subscription rows are kept 400 days
 * (identifiers and dates only, the dispute window) and then deleted; this
 * marks them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestampTz('ended_by_account_purge_at', 6)->nullable();
            $table->index('ended_by_account_purge_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['ended_by_account_purge_at']);
            $table->dropColumn('ended_by_account_purge_at');
        });
    }
};
