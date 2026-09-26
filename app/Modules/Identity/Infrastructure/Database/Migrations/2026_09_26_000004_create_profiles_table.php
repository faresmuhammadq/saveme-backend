<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Profile (glossary): user-scoped and syncable. Created with the account;
 * its editing belongs to B2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('locale', 2);
            $table->string('timezone', 64);
            $table->text('display_name_ciphertext')->nullable();
            $table->integer('version');
            $table->bigInteger('change_seq');
            $table->timestampsTz(6);
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_locale_check CHECK (locale IN ('ar', 'en'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
