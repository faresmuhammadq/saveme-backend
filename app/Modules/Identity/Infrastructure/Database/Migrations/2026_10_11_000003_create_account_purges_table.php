<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Purge progress (spec 022, FR-053): one row per account being purged, the
 * last completed step and the counts accumulated for the completion entry.
 * Operational; deleted by the last step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_purges', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestampTz('started_at', 6);
            $table->string('last_step', 30);
            $table->integer('attempts')->default(0);
            $table->jsonb('counts')->default('{}');
            $table->timestampTz('mail_sent_at', 6)->nullable();
            $table->timestampTz('stall_notified_at', 6)->nullable();
            $table->timestampTz('updated_at', 6);
        });

        DB::statement("ALTER TABLE account_purges ADD CONSTRAINT account_purges_last_step_check CHECK (last_step IN ('begin', 'erase_name', 'revoke_invitations', 'resolve_owned_wallets', 'leave_wallets', 'budgets', 'categories', 'subscriptions', 'exports', 'completion_mail', 'erase_identity'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_purges');
    }
};
