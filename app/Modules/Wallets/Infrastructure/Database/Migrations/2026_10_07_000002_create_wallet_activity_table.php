<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Activity Entry (glossary, ADR-0020, spec 016): one recorded act in a wallet,
 * identifiers only. Append-only and not syncable: no version, change_seq or
 * tombstone. actor_user_id carries no foreign key (users belong to Identity,
 * P-02). The wallet FK cascades so the daily wallet purge is never blocked by
 * entries that have not aged out yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_activity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
            $table->timestampTz('occurred_at', 6);
            $table->uuid('actor_user_id')->nullable();
            $table->string('action', 32);
            $table->string('subject_type', 16);
            $table->uuid('subject_id');
            $table->string('role', 8)->nullable();
        });

        DB::statement("ALTER TABLE wallet_activity ADD CONSTRAINT wallet_activity_action_check CHECK (action IN ('created', 'edited', 'deleted', 'restored', 'archived', 'ownership_transferred', 'confirmed', 'skipped', 'joined', 'left', 'removed', 'role_changed', 'role_limited_by_plan', 'invited', 'invitation_revoked'))");
        DB::statement("ALTER TABLE wallet_activity ADD CONSTRAINT wallet_activity_subject_type_check CHECK (subject_type IN ('wallet', 'transaction', 'recurring_rule', 'occurrence', 'member', 'invitation'))");
        DB::statement("ALTER TABLE wallet_activity ADD CONSTRAINT wallet_activity_role_check CHECK (role IS NULL OR role IN ('owner', 'editor', 'viewer'))");
        DB::statement('CREATE INDEX wallet_activity_wallet_page_index ON wallet_activity (wallet_id, occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX wallet_activity_occurred_at_index ON wallet_activity (occurred_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_activity');
    }
};
