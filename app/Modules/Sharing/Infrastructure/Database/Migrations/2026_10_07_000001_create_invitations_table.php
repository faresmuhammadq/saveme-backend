<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Invitation (glossary, spec 016): an offer to join a wallet with a role.
 * Wallet scope: stamped from the wallet's counter. wallet_id, invited_by and
 * responder_user_id carry no foreign key: wallets belong to Wallets and users
 * to Identity (P-02). The invited address is sealed under the inviter's data
 * key beside its keyed lookup value and erased (set null) in the write that
 * closes the invitation; the token is kept as a SHA-256 hash only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->uuid('invited_by');
            $table->string('role', 16);
            $table->string('channel', 8);
            $table->text('address_sealed')->nullable();
            $table->string('address_lookup', 64)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 16);
            $table->timestampTz('expires_at', 6);
            $table->uuid('responder_user_id')->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('editor', 'viewer'))");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_channel_check CHECK (channel IN ('email', 'link'))");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_status_check CHECK (status IN ('pending', 'accepted', 'declined', 'revoked', 'expired'))");
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_change_seq_check CHECK (change_seq >= 1)');
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_pending_live_check CHECK ((status = 'pending') = (deleted_at IS NULL))");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_link_has_no_address_check CHECK (channel = 'email' OR (address_sealed IS NULL AND address_lookup IS NULL))");
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_closed_has_no_address_check CHECK (deleted_at IS NULL OR (address_sealed IS NULL AND address_lookup IS NULL))');

        DB::statement('CREATE UNIQUE INDEX invitations_pending_address_unique ON invitations (wallet_id, address_lookup) WHERE deleted_at IS NULL AND address_lookup IS NOT NULL');
        DB::statement('CREATE INDEX invitations_wallet_change_seq_index ON invitations (wallet_id, change_seq)');
        DB::statement('CREATE INDEX invitations_wallet_live_index ON invitations (wallet_id, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX invitations_expires_at_live_index ON invitations (expires_at) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX invitations_tombstone_index ON invitations (deleted_at) WHERE deleted_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
