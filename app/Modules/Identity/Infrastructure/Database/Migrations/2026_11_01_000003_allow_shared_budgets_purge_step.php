<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The account purge gains a `shared_budgets` step between `leave_wallets` and
 * `budgets` (ADR-0027 rule 15): owned shared budgets pass to their earliest
 * member or are deleted, and the person leaves the rest. Replaces the
 * last-step check to name it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE account_purges DROP CONSTRAINT account_purges_last_step_check');
        DB::statement("ALTER TABLE account_purges ADD CONSTRAINT account_purges_last_step_check CHECK (last_step IN ('begin', 'erase_name', 'revoke_invitations', 'resolve_owned_wallets', 'leave_wallets', 'shared_budgets', 'budgets', 'categories', 'subscriptions', 'notifications', 'exports', 'completion_mail', 'erase_identity'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE account_purges SET last_step = 'leave_wallets' WHERE last_step = 'shared_budgets'");
        DB::statement('ALTER TABLE account_purges DROP CONSTRAINT account_purges_last_step_check');
        DB::statement("ALTER TABLE account_purges ADD CONSTRAINT account_purges_last_step_check CHECK (last_step IN ('begin', 'erase_name', 'revoke_invitations', 'resolve_owned_wallets', 'leave_wallets', 'budgets', 'categories', 'subscriptions', 'notifications', 'exports', 'completion_mail', 'erase_identity'))");
    }
};
