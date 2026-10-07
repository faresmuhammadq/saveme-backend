<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Play Account Reference (glossary): the obfuscated account id an account
 * gives Google Play, a keyed hash of its id (spec 024, FR-005). user_id
 * carries no foreign key: users belong to Identity (P-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_account_references', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->char('reference', 64)->unique();
            $table->string('key_id', 32);
            $table->timestampTz('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_account_references');
    }
};
