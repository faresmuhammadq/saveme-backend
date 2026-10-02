<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The shared sequence ends (spec 013, R-5; spec 007 R-19): every user-scoped
 * write now stamps from users.change_seq. down() recreates the sequence above
 * the highest stamp in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS sync_change_seq');
    }

    public function down(): void
    {
        $start = (int) DB::scalar('select coalesce(max(change_seq), 0) + 1 from users');

        DB::statement("CREATE SEQUENCE IF NOT EXISTS sync_change_seq START WITH {$start}");
    }
};
