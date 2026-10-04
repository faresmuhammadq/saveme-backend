<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Payment Claim (glossary; spec 020, data-model): one visitor's statement
 * that they paid a plan's InstaPay price. Not user-scoped: it has an address,
 * not an account. The address and the receipt are sealed under Billing's
 * keyring; both are erased 90 days after the claim closes. reviewed_by carries
 * no foreign key: operators belong to Admin (P-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reference', 32);
            $table->text('address_sealed')->nullable();
            $table->string('address_lookup', 64)->nullable();
            $table->string('locale', 2);
            $table->string('receipt_path', 255)->nullable();
            $table->string('receipt_media_type', 16)->nullable();
            $table->integer('receipt_bytes')->nullable();
            $table->string('status', 10);
            $table->timestampTz('expires_at', 6);
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at', 6)->nullable();
            $table->string('reject_reason', 24)->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('closed_at', 6)->nullable();
            $table->timestampTz('erased_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index('reference');
            $table->index(['status', 'created_at', 'id']);
        });

        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_reference_check CHECK (reference ~ '^[A-Z0-9]{8,32}$')");
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_locale_check CHECK (locale IN ('en', 'ar'))");
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_receipt_media_type_check CHECK (receipt_media_type IN ('image/jpeg', 'image/png', 'image/webp'))");
        DB::statement('ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_receipt_bytes_check CHECK (receipt_bytes BETWEEN 1 AND 3145728)');
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_status_check CHECK (status IN ('submitted', 'approved', 'rejected', 'expired'))");
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_reject_reason_check CHECK (reject_reason IN ('reference_not_found', 'amount_mismatch', 'duplicate', 'other'))");
        DB::statement('ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_review_note_check CHECK (char_length(review_note) <= 500)');
        DB::statement('ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_amount_check CHECK (amount_minor >= 0)');
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_reviewed_check CHECK ((status IN ('approved', 'rejected')) = (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))");
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_rejected_check CHECK ((status = 'rejected') = (reject_reason IS NOT NULL))");
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_closed_check CHECK (status NOT IN ('rejected', 'expired') OR closed_at IS NOT NULL)");
        DB::statement("ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_submitted_open_check CHECK (status <> 'submitted' OR closed_at IS NULL)");
        DB::statement('ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_receipt_check CHECK ((receipt_path IS NULL) = (receipt_media_type IS NULL) AND (receipt_path IS NULL) = (receipt_bytes IS NULL))');
        DB::statement('ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_erased_check CHECK (erased_at IS NULL OR (address_sealed IS NULL AND address_lookup IS NULL AND receipt_path IS NULL))');
        DB::statement('ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_unerased_check CHECK (erased_at IS NOT NULL OR (address_sealed IS NOT NULL AND address_lookup IS NOT NULL))');
        DB::statement("CREATE UNIQUE INDEX payment_claims_one_approved_reference ON payment_claims (reference) WHERE status = 'approved'");
        DB::statement("CREATE INDEX payment_claims_expiry_sweep ON payment_claims (expires_at) WHERE status = 'submitted'");
        DB::statement('CREATE INDEX payment_claims_purge ON payment_claims (closed_at) WHERE closed_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
    }
};
