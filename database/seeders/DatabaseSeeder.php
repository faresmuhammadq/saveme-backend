<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Module seeders, in dependency order. Each module that has reference data
     * ships App\Modules\<Context>\Infrastructure\Database\Seeders\<Context>Seeder;
     * one that has none ships nothing and is skipped. Identity first, since
     * everything else belongs to a user; Billing before Wallets, since plans
     * gate what a wallet may do.
     */
    private const MODULE_ORDER = [
        'Identity', 'Billing', 'Wallets', 'Budgeting', 'Sharing',
        'Sync', 'Reporting', 'Notifications', 'Admin',
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (self::MODULE_ORDER as $module) {
            $seeder = "App\\Modules\\{$module}\\Infrastructure\\Database\\Seeders\\{$module}Seeder";

            // No module ships a seeder yet, so PHPStan knows this is false today;
            // it becomes true the day one does, which is the point of the check.
            // @phpstan-ignore function.impossibleType
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
