<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Per-account key material: the person's data key, wrapped by the keyring key
 * named in `kid`. Deleting the row at purge makes every sealed value of that
 * person unreadable, backups included (security baseline, Deletion rule 3).
 *
 * The foreign key is deferred to commit: the data key must exist before the
 * address can be sealed, and the sealed address before the user row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_keys', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->text('wrapped_key');
            $table->string('kid', 32);
            $table->timestampsTz(6);
        });

        DB::statement('ALTER TABLE user_keys ADD CONSTRAINT user_keys_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_keys');
    }
};
