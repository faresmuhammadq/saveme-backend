<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The account purge gains a `notifications` step between `subscriptions` and
 * `exports` (spec 023, FR-044): the person's push registrations, preferences
 * and notification records go. Replaces the last-step check to name it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE account_purges DROP CONSTRAINT account_purges_last_step_check');
        DB::statement("ALTER TABLE account_purges ADD CONSTRAINT account_purges_last_step_check CHECK (last_step IN ('begin', 'erase_name', 'revoke_invitations', 'resolve_owned_wallets', 'leave_wallets', 'budgets', 'categories', 'subscriptions', 'notifications', 'exports', 'completion_mail', 'erase_identity'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE account_purges SET last_step = 'subscriptions' WHERE last_step = 'notifications'");
        DB::statement('ALTER TABLE account_purges DROP CONSTRAINT account_purges_last_step_check');
        DB::statement("ALTER TABLE account_purges ADD CONSTRAINT account_purges_last_step_check CHECK (last_step IN ('begin', 'erase_name', 'revoke_invitations', 'resolve_owned_wallets', 'leave_wallets', 'budgets', 'categories', 'subscriptions', 'exports', 'completion_mail', 'erase_identity'))");
    }
};
