<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Subscription (glossary): one person's access to a plan through one
 * channel (ADR-0013 rules 2–4). The free tier has no row (rule 13).
 * user_id carries no foreign key: users belong to Identity (P-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('plan_id');
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->string('provider', 12);
            $table->string('provider_ref', 255);
            $table->string('status', 10);
            $table->timestampTz('current_period_start', 6);
            $table->timestampTz('current_period_end', 6)->nullable();
            $table->timestampTz('grace_until', 6)->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->jsonb('source_meta')->default('{}');
            $table->timestampsTz(6);

            $table->index(['user_id', 'status']);
            $table->index(['status', 'current_period_end']);
            $table->index(['status', 'grace_until']);
            $table->unique(['provider', 'provider_ref']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_provider_check CHECK (provider IN ('google_play', 'instapay', 'manual'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('active', 'grace', 'expired', 'revoked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
