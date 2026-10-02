<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Category (glossary, ADR-0014): one table, two owners. A person's category
 * has user_id and a name; a platform category has a system_key, names in
 * both languages, icon, colour and position, and is reference data. Two
 * CHECKs keep the shapes apart. user_id carries no foreign key: users belong
 * to Identity (P-02). parent_id is reserved for nesting and always null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('system_key', 40)->nullable()->unique();
            $table->string('kind', 7);
            $table->string('name', 50)->nullable();
            $table->jsonb('names')->nullable();
            $table->string('icon', 40)->nullable();
            $table->char('color', 7)->nullable();
            $table->smallInteger('position')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->timestampTz('archived_at', 6)->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq')->nullable();
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_system_key_check CHECK (system_key IS NULL OR system_key ~ '^[a-z][a-z0-9_]{1,39}\$')");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_kind_check CHECK (kind IN ('income', 'expense'))");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_name_check CHECK (name IS NULL OR btrim(name) <> '')");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_icon_check CHECK (icon IS NULL OR icon ~ '^[a-z0-9_]{1,40}\$')");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_color_check CHECK (color IS NULL OR color ~ '^#[0-9A-F]{6}\$')");
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_parent_reserved_check CHECK (parent_id IS NULL)');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_version_check CHECK (version >= 1)');
        DB::statement(<<<'SQL'
            ALTER TABLE categories ADD CONSTRAINT categories_owner_shape_check CHECK (
                (user_id IS NOT NULL AND system_key IS NULL AND name IS NOT NULL AND names IS NULL AND position IS NULL AND change_seq IS NOT NULL)
                OR
                (user_id IS NULL AND system_key IS NOT NULL AND name IS NULL AND names IS NOT NULL AND icon IS NOT NULL AND color IS NOT NULL AND position IS NOT NULL AND change_seq IS NULL AND deleted_at IS NULL)
            )
            SQL);

        DB::statement('CREATE INDEX categories_user_created_index ON categories (user_id, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX categories_user_name_index ON categories (user_id, name, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX categories_platform_position_unique ON categories (position) WHERE user_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
