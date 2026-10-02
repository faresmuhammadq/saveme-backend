<?php

namespace App\Modules\Wallets\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Wallets & Ledger's reference data: the platform's default categories.
 * Idempotent and safe in production; run at every deploy.
 */
class WalletsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformCategorySeeder::class);
    }
}
