<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Play Notification (glossary): the inbox of verified real-time developer
 * notifications (spec 024, FR-034). The Pub/Sub message id is the replay
 * guard; the purchase token is sealed until the row is terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('message_id', 128)->unique();
            $table->string('kind', 16);
            $table->smallInteger('notification_type')->nullable();
            $table->string('product_id', 150)->nullable();
            $table->char('token_lookup', 64)->nullable();
            $table->text('token_sealed')->nullable();
            $table->timestampTz('event_at', 6)->nullable();
            $table->timestampTz('published_at', 6)->nullable();
            $table->timestampTz('received_at', 6);
            $table->string('state', 10);
            $table->string('ignored_reason', 24)->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('updated_at', 6);

            $table->index('received_at');
            $table->index(['state', 'received_at']);
            $table->index('token_lookup');
        });

        DB::statement("ALTER TABLE play_notifications ADD CONSTRAINT play_notifications_kind_check CHECK (kind IN ('subscription', 'one_time', 'voided', 'test', 'malformed'))");
        DB::statement("ALTER TABLE play_notifications ADD CONSTRAINT play_notifications_state_check CHECK (state IN ('received', 'processed', 'ignored', 'failed'))");
        DB::statement("ALTER TABLE play_notifications ADD CONSTRAINT play_notifications_reason_check CHECK (ignored_reason IS NULL OR ignored_reason IN ('test', 'foreign_subscription', 'foreign_package', 'malformed', 'purged_account'))");
        DB::statement("ALTER TABLE play_notifications ADD CONSTRAINT play_notifications_ignored_check CHECK ((state = 'ignored') = (ignored_reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('play_notifications');
    }
};
