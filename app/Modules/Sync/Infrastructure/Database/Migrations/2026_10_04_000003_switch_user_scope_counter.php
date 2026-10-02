<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Moves the user scope from the shared sync_change_seq sequence to the
 * per-user counter on users.change_seq (spec 013, data-model "The switch
 * migration"). A one-off data move over the tables of two modules, in plain
 * SQL: no class of another module is referenced.
 *
 * RUN IT IN MAINTENANCE MODE (php artisan down, migrate, up; research R-5):
 * code that still draws from the sequence never touches users, so a write
 * during the backfill could land above a counter that was just set. The
 * SHARE ROW EXCLUSIVE lock stops concurrent writers of users only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');

            DB::statement(<<<'SQL'
                CREATE TEMPORARY TABLE user_scope_base ON COMMIT DROP AS
                SELECT u.id AS user_id,
                       greatest(
                           coalesce((SELECT max(p.change_seq) FROM profiles p WHERE p.user_id = u.id), 0),
                           coalesce((SELECT max(s.change_seq) FROM device_sessions s WHERE s.user_id = u.id), 0),
                           coalesce((SELECT max(c.change_seq) FROM categories c WHERE c.user_id = u.id), 0)
                       ) AS base
                FROM users u
                SQL);

            DB::statement(<<<'SQL'
                UPDATE wallet_members m
                SET member_change_seq = b.base + ranked.position
                FROM (
                    SELECT id, user_id, row_number() OVER (PARTITION BY user_id ORDER BY created_at, id) AS position
                    FROM wallet_members
                ) ranked
                JOIN user_scope_base b ON b.user_id = ranked.user_id
                WHERE m.id = ranked.id
                SQL);

            // A membership whose person has no users row still needs a stamp.
            DB::statement(<<<'SQL'
                UPDATE wallet_members m
                SET member_change_seq = ranked.position
                FROM (
                    SELECT id, row_number() OVER (PARTITION BY user_id ORDER BY created_at, id) AS position
                    FROM wallet_members
                ) ranked
                WHERE m.id = ranked.id AND m.member_change_seq IS NULL
                SQL);

            DB::statement(<<<'SQL'
                UPDATE users u
                SET change_seq = greatest(
                    b.base,
                    coalesce((SELECT max(m.member_change_seq) FROM wallet_members m WHERE m.user_id = u.id), 0)
                )
                FROM user_scope_base b
                WHERE b.user_id = u.id
                SQL);

            DB::statement('ALTER TABLE wallet_members ALTER COLUMN member_change_seq SET NOT NULL');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wallet_members ALTER COLUMN member_change_seq DROP NOT NULL');
    }
};
