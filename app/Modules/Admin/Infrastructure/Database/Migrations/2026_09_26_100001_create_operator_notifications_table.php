<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Operator Notification (glossary; spec 007, R-14): something operators are
 * told about, by identifier only. Unique on the event that caused it, so a
 * redelivered event adds nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 40);
            $table->uuid('subject_user_id');
            $table->timestampTz('occurred_at', 6);
            $table->jsonb('payload');
            $table->uuid('source_event_id')->unique();
            $table->timestampTz('created_at', 6);

            $table->index('occurred_at');
        });

        DB::statement("ALTER TABLE operator_notifications ADD CONSTRAINT operator_notifications_kind_check CHECK (kind IN ('deletion_cancelled_by_sign_in'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_notifications');
    }
};
