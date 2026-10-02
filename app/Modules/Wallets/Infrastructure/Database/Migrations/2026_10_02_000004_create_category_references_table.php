<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Category Reference (glossary): a read-only projection of a person's
 * category's display fields, published into a wallet so its members can
 * render a partner's category. Wallet scope, syncable, never authored by a
 * client. At most one live row per (wallet, category); a re-publish revives
 * the tombstoned row rather than inserting a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('wallets');
            $table->uuid('category_id');
            $table->foreign('category_id')->references('id')->on('categories');
            $table->uuid('owner_user_id');
            $table->string('kind', 7);
            $table->string('name', 50);
            $table->string('icon', 40)->nullable();
            $table->char('color', 7)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE category_references ADD CONSTRAINT category_references_kind_check CHECK (kind IN ('income', 'expense'))");
        DB::statement('ALTER TABLE category_references ADD CONSTRAINT category_references_version_check CHECK (version >= 1)');
        DB::statement('CREATE UNIQUE INDEX category_references_live_pair_unique ON category_references (wallet_id, category_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX category_references_wallet_created_index ON category_references (wallet_id, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX category_references_category_live_index ON category_references (category_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('category_references');
    }
};
