<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Wires every module by convention, so a new module works by existing.
 *
 * For each directory under app/Modules/<Context>/ it loads the module's
 * migrations and teaches factories where the module keeps them. It lives in
 * app/Providers rather than the shared kernel because it is persistence
 * wiring, and the shared kernel holds none (constitution P-02 rule 5).
 *
 * Layout (docs/engineering-conventions.md):
 *   Infrastructure/Models/X.php                     an Eloquent model
 *   Infrastructure/Database/Migrations/*.php        its migrations
 *   Infrastructure/Database/Factories/XFactory.php  its factory
 *   Infrastructure/Database/Seeders/<Context>Seeder.php
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ($this->modules() as $module) {
            $migrations = app_path("Modules/{$module}/Infrastructure/Database/Migrations");

            if (is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }
        }

        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $factory = preg_match('/^App\\\\Modules\\\\(\w+)\\\\Infrastructure\\\\Models\\\\(.+)$/', $modelName, $match)
                ? "App\\Modules\\{$match[1]}\\Infrastructure\\Database\\Factories\\{$match[2]}Factory"
                : 'Database\\Factories\\'.class_basename($modelName).'Factory';

            /** @var class-string<Factory<Model>> $factory */
            return $factory;
        });
    }

    /**
     * @return list<string>
     */
    private function modules(): array
    {
        $root = app_path('Modules');

        if (! is_dir($root)) {
            return [];
        }

        return array_values(array_filter(
            array_diff(scandir($root) ?: [], ['.', '..', 'Shared']),
            fn (string $entry): bool => is_dir("{$root}/{$entry}"),
        ));
    }
}
