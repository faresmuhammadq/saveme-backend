<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Operator Account (glossary; spec 018, data-model). Email, display name and
 * the second-factor secrets are sealed (R-10); the email is found by its
 * keyed lookup value. An active account always has a factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('email_lookup', 64)->unique();
            $table->text('email_sealed');
            $table->text('display_name_sealed');
            $table->text('password_hash');
            $table->string('state', 30);
            $table->text('secret_sealed')->nullable();
            $table->text('pending_secret_sealed')->nullable();
            $table->timestampTz('pending_secret_created_at', 6)->nullable();
            $table->bigInteger('last_code_step')->nullable();
            $table->timestampTz('enrolled_at', 6)->nullable();
            $table->integer('credential_epoch')->default(0);
            $table->timestampTz('password_changed_at', 6)->nullable();
            $table->timestampTz('temporary_password_expires_at', 6)->nullable();
            $table->timestampTz('last_signed_in_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement("ALTER TABLE operator_accounts ADD CONSTRAINT operator_accounts_state_check CHECK (state IN ('awaiting_password_change', 'awaiting_enrolment', 'active', 'deactivated'))");
        DB::statement("ALTER TABLE operator_accounts ADD CONSTRAINT operator_accounts_active_has_factor_check CHECK (state <> 'active' OR (secret_sealed IS NOT NULL AND enrolled_at IS NOT NULL))");
        DB::statement("ALTER TABLE operator_accounts ADD CONSTRAINT operator_accounts_enrolment_has_no_secret_check CHECK (state <> 'awaiting_enrolment' OR secret_sealed IS NULL)");
        DB::statement('ALTER TABLE operator_accounts ADD CONSTRAINT operator_accounts_secret_enrolled_check CHECK ((secret_sealed IS NULL) = (enrolled_at IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_accounts');
    }
};
