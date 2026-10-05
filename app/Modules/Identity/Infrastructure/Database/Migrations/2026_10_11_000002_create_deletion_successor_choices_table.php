<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The new owner a departing person chose for a shared wallet they own (spec
 * 022, FR-035 to FR-038). Held only while the deletion is pending. wallet_id
 * and member_user_id carry no foreign key: wallets belong to Wallets (P-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_successor_choices', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->uuid('wallet_id');
            $table->uuid('member_user_id');
            $table->timestampTz('chosen_at', 6);

            $table->primary(['user_id', 'wallet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_successor_choices');
    }
};
