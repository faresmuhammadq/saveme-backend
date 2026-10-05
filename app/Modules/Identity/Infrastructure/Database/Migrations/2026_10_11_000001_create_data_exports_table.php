<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Export (spec 022, glossary): one requested copy. Not syncable. wallet_id
 * carries no foreign key: wallets belong to Wallets (P-02). At most one
 * export per person is queued or running, enforced by a partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('scope', 10);
            $table->uuid('wallet_id')->nullable();
            $table->string('status', 10);
            $table->string('failure_code', 30)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->timestampTz('requested_at', 6);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_scope_check CHECK (scope IN ('account', 'wallet'))");
        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_wallet_check CHECK ((scope = 'wallet') = (wallet_id IS NOT NULL))");
        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_status_check CHECK (status IN ('queued', 'running', 'ready', 'failed', 'expired'))");
        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_failure_code_check CHECK (failure_code IS NULL OR failure_code IN ('generation_failed', 'wallet_unavailable', 'timed_out'))");
        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_failure_check CHECK ((status = 'failed') = (failure_code IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX data_exports_one_active ON data_exports (user_id) WHERE status IN ('queued', 'running')");
        DB::statement('CREATE INDEX data_exports_list ON data_exports (user_id, requested_at DESC, id DESC)');
        DB::statement('CREATE INDEX data_exports_expiry ON data_exports (status, expires_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
