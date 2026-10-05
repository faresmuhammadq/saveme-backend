<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Notification Read Mark (glossary; spec 021, R-12): one operator has read
 * one operator notification, and when. Identifiers only; not syncable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_notification_reads', function (Blueprint $table) {
            $table->uuid('operator_id');
            $table->uuid('notification_id');
            $table->timestampTz('read_at', 6);

            $table->primary(['operator_id', 'notification_id']);
            $table->foreign('operator_id')->references('id')->on('operator_accounts')->cascadeOnDelete();
            $table->foreign('notification_id')->references('id')->on('operator_notifications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_notification_reads');
    }
};
