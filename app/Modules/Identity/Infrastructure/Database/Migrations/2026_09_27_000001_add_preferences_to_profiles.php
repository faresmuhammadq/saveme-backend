<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Profile preferences (spec 008): a default currency for new wallets and the
 * day the person's budget periods start, with the date that day applies from.
 * Existing profiles take the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->char('default_currency', 3)->default('EGP');
            $table->smallInteger('budget_period_start_day')->default(1);
            $table->date('budget_period_start_day_from')->nullable();
        });

        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_budget_period_start_day_check CHECK (budget_period_start_day BETWEEN 1 AND 28)');
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['default_currency', 'budget_period_start_day', 'budget_period_start_day_from']);
        });
    }
};
