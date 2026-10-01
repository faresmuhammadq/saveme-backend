<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Wallet (glossary): a container of money and the wallet sync scope. The id
 * is the client's UUIDv7. balance_minor is a cache (ADR-0005): opening
 * balance + sum of postings, written only under the row lock. change_seq is
 * the wallet scope's counter (ADR-0008). Money is bigint minor units (P-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 12);
            $table->string('name', 100);
            $table->char('currency', 3);
            $table->bigInteger('opening_balance_minor');
            $table->bigInteger('balance_minor');
            $table->string('icon', 40)->nullable();
            $table->char('color', 7)->nullable();
            $table->timestampTz('archived_at', 6)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_type_check CHECK (type IN ('cash', 'checking', 'savings', 'credit_card', 'loan', 'investment'))");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_name_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_icon_check CHECK (icon IS NULL OR icon ~ '^[a-z0-9_]{1,40}$')");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_color_check CHECK (color IS NULL OR color ~ '^#[0-9A-F]{6}$')");
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_change_seq_check CHECK (change_seq >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
