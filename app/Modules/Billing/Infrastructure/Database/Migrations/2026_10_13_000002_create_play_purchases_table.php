<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Play Purchase (glossary): one Google Play purchase token known to this
 * service (spec 024, FR-006). The token is sealed with a keyed lookup value
 * beside it (unique): a token is linked to one account, once, forever.
 * user_id carries no foreign key: users belong to Identity (P-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('token_lookup', 64)->unique();
            $table->text('token_sealed')->nullable();
            $table->string('kind', 12);
            $table->string('product_id', 150);
            $table->uuid('user_id')->nullable();
            $table->timestampTz('linked_at', 6)->nullable();
            $table->uuid('subscription_id')->nullable();
            $table->foreign('subscription_id')->references('id')->on('subscriptions');
            $table->char('obfuscated_ref', 64)->nullable();
            $table->char('replaces_lookup', 64)->nullable();
            $table->string('play_state', 40);
            $table->timestampTz('acknowledged_at', 6)->nullable();
            $table->boolean('is_test')->default(false);
            $table->string('base_plan_id', 64)->nullable();
            $table->timestampTz('purchased_at', 6);
            $table->timestampTz('synced_at', 6)->nullable();
            $table->timestampTz('unreachable_since', 6)->nullable();
            $table->timestampTz('ended_by_account_purge_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index('user_id');
            $table->index('subscription_id');
            $table->index('replaces_lookup');
            $table->index('ended_by_account_purge_at');
        });

        DB::statement("ALTER TABLE play_purchases ADD CONSTRAINT play_purchases_kind_check CHECK (kind IN ('subscription', 'one_time'))");
        DB::statement("ALTER TABLE play_purchases ADD CONSTRAINT play_purchases_state_check CHECK (play_state IN ('pending', 'active', 'grace', 'canceled', 'on_hold', 'paused', 'expired', 'revoked', 'pending_canceled'))");
        DB::statement('ALTER TABLE play_purchases ADD CONSTRAINT play_purchases_link_check CHECK ((user_id IS NULL) = (linked_at IS NULL))');
        DB::statement('ALTER TABLE play_purchases ADD CONSTRAINT play_purchases_subscription_check CHECK (subscription_id IS NULL OR user_id IS NOT NULL)');
        DB::statement('CREATE INDEX play_purchases_unacknowledged_index ON play_purchases (acknowledged_at) WHERE acknowledged_at IS NULL AND user_id IS NOT NULL');
        DB::statement('CREATE INDEX play_purchases_unlinked_index ON play_purchases (purchased_at) WHERE user_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('play_purchases');
    }
};
