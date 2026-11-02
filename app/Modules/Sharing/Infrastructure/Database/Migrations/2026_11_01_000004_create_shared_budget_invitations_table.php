<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Shared Budget Invitation (glossary, ADR-0027): an offer to join a Shared
 * Budget by a link. Shared Budget scope: stamped from the budget's counter.
 * No address and no role: v1 invites by link only, and every member joins as
 * a member. shared_budget_id and invited_by carry no foreign key: budgets
 * belong to Budgeting and users to Identity (P-02). The token is stored only
 * as its hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_budget_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shared_budget_id');
            $table->uuid('invited_by');
            $table->char('token_hash', 64)->unique();
            $table->string('status', 16);
            $table->timestampTz('expires_at', 6);
            $table->uuid('responder_user_id')->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE shared_budget_invitations ADD CONSTRAINT shared_budget_invitations_status_check CHECK (status IN ('pending', 'accepted', 'declined', 'revoked', 'expired'))");
        DB::statement('ALTER TABLE shared_budget_invitations ADD CONSTRAINT shared_budget_invitations_pending_live_check CHECK ((status = \'pending\') = (deleted_at IS NULL))');
        DB::statement('CREATE INDEX shared_budget_invitations_feed_index ON shared_budget_invitations (shared_budget_id, change_seq)');
        DB::statement('CREATE INDEX shared_budget_invitations_expiry_index ON shared_budget_invitations (expires_at) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_budget_invitations');
    }
};
