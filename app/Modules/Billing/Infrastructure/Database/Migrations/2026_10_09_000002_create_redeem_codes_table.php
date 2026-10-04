<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Redeem Code (glossary; spec 020, data-model): the single-use secret an
 * approved claim issues. Only the SHA-256 of the normalised code is stored.
 * redeemed_by and subscription_id carry no foreign key: users belong to
 * Identity (P-02) and a purge of a person's subscriptions must never be
 * blocked by this record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redeem_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->unique()->constrained('payment_claims')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('status', 10);
            $table->timestampTz('expires_at', 6);
            $table->uuid('redeemed_by')->nullable();
            $table->timestampTz('redeemed_at', 6)->nullable();
            $table->uuid('subscription_id')->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        DB::statement("ALTER TABLE redeem_codes ADD CONSTRAINT redeem_codes_status_check CHECK (status IN ('issued', 'redeemed', 'revoked', 'expired'))");
        DB::statement("ALTER TABLE redeem_codes ADD CONSTRAINT redeem_codes_redeemed_check CHECK ((status = 'redeemed') = (redeemed_by IS NOT NULL AND redeemed_at IS NOT NULL AND subscription_id IS NOT NULL))");
        DB::statement("ALTER TABLE redeem_codes ADD CONSTRAINT redeem_codes_revoked_check CHECK ((status = 'revoked') = (revoked_at IS NOT NULL))");
        DB::statement("CREATE INDEX redeem_codes_expiry_sweep ON redeem_codes (expires_at) WHERE status = 'issued'");
    }

    public function down(): void
    {
        Schema::dropIfExists('redeem_codes');
    }
};
