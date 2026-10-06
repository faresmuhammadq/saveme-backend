<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Push Notification and Push Delivery (glossary, spec 023, ADR-0025): the
 * ledger of every message, identifiers and states only. The dedupe key,
 * unique per person, is the idempotency of every notification listener and
 * pass. Kept 30 days. A delivery's registration_id carries no foreign key:
 * the registration may already be pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type', 40);
            $table->string('category', 20);
            $table->string('dedupe_key', 160);
            $table->jsonb('data');
            $table->string('collapse_key', 80);
            $table->string('state', 10);
            $table->string('skip_reason', 20)->nullable();
            $table->timestampTz('not_before', 6)->nullable();
            $table->timestampTz('sending_since', 6)->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['user_id', 'dedupe_key']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('occurrence_due', 'occurrence_auto_confirm_refused', 'occurrences_due_summary', 'budget_exceeded', 'invitation_received', 'member_role_changed', 'member_removed', 'wallet_ownership_received', 'subscription_ending', 'subscription_lapsed', 'export_ready', 'deletion_reminder'))");
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_category_check CHECK (category IN ('recurring_due', 'budget_exceeded', 'sharing', 'billing', 'export_ready', 'deletion_reminder'))");
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_state_check CHECK (state IN ('pending', 'held', 'sending', 'sent', 'skipped', 'failed'))");
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_skip_reason_check CHECK (skip_reason IS NULL OR skip_reason IN ('category_off', 'no_device', 'rate_limited', 'collapsed', 'inactive_account', 'disabled', 'summarised'))");
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_skipped_shape_check CHECK ((state = 'skipped') = (skip_reason IS NOT NULL))");
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_held_shape_check CHECK ((state = 'held') = (not_before IS NOT NULL))");
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_sent_shape_check CHECK ((state = 'sent') = (sent_at IS NOT NULL))");
        DB::statement("CREATE INDEX push_notifications_open_index ON push_notifications (state, not_before) WHERE state IN ('held', 'sending')");
        DB::statement('CREATE INDEX push_notifications_rate_index ON push_notifications (user_id, sent_at) WHERE sent_at IS NOT NULL');

        Schema::create('push_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->foreign('notification_id')->references('id')->on('push_notifications')->cascadeOnDelete();
            $table->uuid('registration_id');
            $table->string('state', 10);
            $table->smallInteger('attempts')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->timestampsTz(6);

            $table->unique(['notification_id', 'registration_id']);
            $table->index(['state', 'updated_at']);
        });

        DB::statement("ALTER TABLE push_deliveries ADD CONSTRAINT push_deliveries_state_check CHECK (state IN ('pending', 'sent', 'pruned', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
        Schema::dropIfExists('push_notifications');
    }
};
