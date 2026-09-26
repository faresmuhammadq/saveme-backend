<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The change_seq source for syncable records until the Sync module (B14)
 * takes over commit-order assignment (spec 007, R-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS sync_change_seq');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS sync_change_seq');
    }
};
