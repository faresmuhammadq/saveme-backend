<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Wallet Member (glossary): one person's role in one wallet. user_id carries
 * no foreign key: users belong to Identity (P-02). Ownership lives only here:
 * a partial unique index allows at most one active owner per wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->uuid('user_id');
            $table->string('role', 8);
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();

            $table->index(['wallet_id', 'change_seq']);
        });

        DB::statement("ALTER TABLE wallet_members ADD CONSTRAINT wallet_members_role_check CHECK (role IN ('owner', 'editor', 'viewer'))");
        DB::statement('ALTER TABLE wallet_members ADD CONSTRAINT wallet_members_version_check CHECK (version >= 1)');
        DB::statement('CREATE UNIQUE INDEX wallet_members_active_member_unique ON wallet_members (wallet_id, user_id) WHERE deleted_at IS NULL');
        DB::statement("CREATE UNIQUE INDEX wallet_members_active_owner_unique ON wallet_members (wallet_id) WHERE role = 'owner' AND deleted_at IS NULL");
        DB::statement('CREATE INDEX wallet_members_user_active_index ON wallet_members (user_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_members');
    }
};
