<?php

namespace App\Modules\Wallets\Infrastructure\Database\Seeders;

use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Domain\Categories\CategoryRules;
use App\Modules\Wallets\Domain\Categories\SystemKey;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The platform's default categories (spec 011 section 3, ADR-0014), as
 * reference data: idempotent, keyed on `system_key`, safe in production and
 * run at every deploy. A key keeps its id for ever; its names, icon, colour
 * and position follow the definitions; a key dropped from the definitions is
 * retired (`archived_at`), never deleted, and re-adding it un-retires it.
 */
class PlatformCategorySeeder extends Seeder
{
    /**
     * @return list<array{system_key: string, kind: string, names: array{en: string, ar: string}, icon: string, color: string, position: int}>
     */
    public static function definitions(): array
    {
        return [
            ['system_key' => 'groceries', 'kind' => 'expense', 'names' => ['en' => 'Groceries', 'ar' => 'بقالة'], 'icon' => 'cart', 'color' => '#2E7D32', 'position' => 1],
            ['system_key' => 'dining_out', 'kind' => 'expense', 'names' => ['en' => 'Dining out', 'ar' => 'مطاعم وكافيهات'], 'icon' => 'restaurant', 'color' => '#EF6C00', 'position' => 2],
            ['system_key' => 'transport', 'kind' => 'expense', 'names' => ['en' => 'Transport', 'ar' => 'مواصلات'], 'icon' => 'bus', 'color' => '#1565C0', 'position' => 3],
            ['system_key' => 'fuel', 'kind' => 'expense', 'names' => ['en' => 'Fuel', 'ar' => 'بنزين'], 'icon' => 'fuel', 'color' => '#455A64', 'position' => 4],
            ['system_key' => 'rent', 'kind' => 'expense', 'names' => ['en' => 'Rent', 'ar' => 'إيجار'], 'icon' => 'home', 'color' => '#6D4C41', 'position' => 5],
            ['system_key' => 'utilities', 'kind' => 'expense', 'names' => ['en' => 'Utilities', 'ar' => 'كهرباء ومياه وغاز'], 'icon' => 'bolt', 'color' => '#F9A825', 'position' => 6],
            ['system_key' => 'phone_internet', 'kind' => 'expense', 'names' => ['en' => 'Phone & internet', 'ar' => 'موبايل وإنترنت'], 'icon' => 'phone', 'color' => '#00838F', 'position' => 7],
            ['system_key' => 'health', 'kind' => 'expense', 'names' => ['en' => 'Health', 'ar' => 'صحة وعلاج'], 'icon' => 'health', 'color' => '#C62828', 'position' => 8],
            ['system_key' => 'education', 'kind' => 'expense', 'names' => ['en' => 'Education', 'ar' => 'تعليم'], 'icon' => 'school', 'color' => '#283593', 'position' => 9],
            ['system_key' => 'clothing', 'kind' => 'expense', 'names' => ['en' => 'Clothing', 'ar' => 'ملابس'], 'icon' => 'shirt', 'color' => '#AD1457', 'position' => 10],
            ['system_key' => 'household', 'kind' => 'expense', 'names' => ['en' => 'Home supplies', 'ar' => 'مستلزمات البيت'], 'icon' => 'sofa', 'color' => '#5D4037', 'position' => 11],
            ['system_key' => 'personal_care', 'kind' => 'expense', 'names' => ['en' => 'Personal care', 'ar' => 'عناية شخصية'], 'icon' => 'care', 'color' => '#8E24AA', 'position' => 12],
            ['system_key' => 'entertainment', 'kind' => 'expense', 'names' => ['en' => 'Entertainment', 'ar' => 'ترفيه'], 'icon' => 'ticket', 'color' => '#7B1FA2', 'position' => 13],
            ['system_key' => 'subscriptions', 'kind' => 'expense', 'names' => ['en' => 'Subscriptions', 'ar' => 'اشتراكات'], 'icon' => 'repeat', 'color' => '#3949AB', 'position' => 14],
            ['system_key' => 'travel', 'kind' => 'expense', 'names' => ['en' => 'Travel', 'ar' => 'سفر'], 'icon' => 'plane', 'color' => '#0277BD', 'position' => 15],
            ['system_key' => 'kids', 'kind' => 'expense', 'names' => ['en' => 'Kids', 'ar' => 'أطفال'], 'icon' => 'child', 'color' => '#F06292', 'position' => 16],
            ['system_key' => 'gifts_given', 'kind' => 'expense', 'names' => ['en' => 'Gifts', 'ar' => 'هدايا'], 'icon' => 'gift', 'color' => '#D81B60', 'position' => 17],
            ['system_key' => 'charity', 'kind' => 'expense', 'names' => ['en' => 'Charity & zakat', 'ar' => 'زكاة وصدقات'], 'icon' => 'heart', 'color' => '#00897B', 'position' => 18],
            ['system_key' => 'other_expense', 'kind' => 'expense', 'names' => ['en' => 'Other expenses', 'ar' => 'مصروفات أخرى'], 'icon' => 'dots', 'color' => '#757575', 'position' => 19],
            ['system_key' => 'salary', 'kind' => 'income', 'names' => ['en' => 'Salary', 'ar' => 'مرتب'], 'icon' => 'wallet', 'color' => '#2E7D32', 'position' => 20],
            ['system_key' => 'business_income', 'kind' => 'income', 'names' => ['en' => 'Business income', 'ar' => 'دخل تجاري'], 'icon' => 'store', 'color' => '#1B5E20', 'position' => 21],
            ['system_key' => 'freelance', 'kind' => 'income', 'names' => ['en' => 'Freelance', 'ar' => 'عمل حر'], 'icon' => 'laptop', 'color' => '#00695C', 'position' => 22],
            ['system_key' => 'investment_income', 'kind' => 'income', 'names' => ['en' => 'Investment returns', 'ar' => 'عائد استثمار'], 'icon' => 'chart', 'color' => '#4527A0', 'position' => 23],
            ['system_key' => 'gifts_received', 'kind' => 'income', 'names' => ['en' => 'Gifts received', 'ar' => 'هدايا مستلمة'], 'icon' => 'gift', 'color' => '#C2185B', 'position' => 24],
            ['system_key' => 'refunds', 'kind' => 'income', 'names' => ['en' => 'Refunds', 'ar' => 'مبالغ مستردة'], 'icon' => 'undo', 'color' => '#0097A7', 'position' => 25],
            ['system_key' => 'other_income', 'kind' => 'income', 'names' => ['en' => 'Other income', 'ar' => 'دخل آخر'], 'icon' => 'dots', 'color' => '#616161', 'position' => 26],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function validate(array $definition): void
    {
        $key = (new SystemKey((string) ($definition['system_key'] ?? '')))->value;

        if (CategoryKind::tryFrom((string) ($definition['kind'] ?? '')) === null) {
            throw new InvalidArgumentException("Platform category [{$key}] has an unknown kind.");
        }

        $names = (array) ($definition['names'] ?? []);

        foreach (['ar', 'en'] as $language) {
            $name = $names[$language] ?? null;

            if (! is_string($name) || ! CategoryRules::isValidName($name)) {
                throw new InvalidArgumentException("Platform category [{$key}] needs a [{$language}] name of 1 to 50 characters.");
            }
        }

        if (preg_match('/^[a-z0-9_]{1,40}$/', (string) ($definition['icon'] ?? '')) !== 1) {
            throw new InvalidArgumentException("Platform category [{$key}] has a malformed icon.");
        }

        if (preg_match('/^#[0-9A-F]{6}$/', (string) ($definition['color'] ?? '')) !== 1) {
            throw new InvalidArgumentException("Platform category [{$key}] has a malformed colour.");
        }

        if (! is_int($definition['position'] ?? null) || $definition['position'] < 1) {
            throw new InvalidArgumentException("Platform category [{$key}] needs a positive position.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     */
    public static function validateAll(array $definitions): void
    {
        array_walk($definitions, fn (array $definition) => self::validate($definition));

        foreach (['system_key', 'position'] as $member) {
            $values = array_column($definitions, $member);

            if (count($values) !== count(array_unique($values))) {
                throw new InvalidArgumentException("Platform category [{$member}] values must be unique.");
            }
        }
    }

    public function run(): void
    {
        $this->apply(self::definitions());
    }

    /**
     * @param  list<array{system_key: string, kind: string, names: array{en: string, ar: string}, icon: string, color: string, position: int}>  $definitions
     */
    public function apply(array $definitions): void
    {
        self::validateAll($definitions);

        DB::transaction(function () use ($definitions): void {
            /** @var Collection<string, Category> $existing */
            $existing = Category::query()->whereNull('user_id')->lockForUpdate()->get()->keyBy('system_key');
            $wanted = array_column($definitions, null, 'system_key');

            foreach ($definitions as $definition) {
                $row = $existing->get($definition['system_key']);

                if ($row !== null && $row->kind !== $definition['kind']) {
                    throw new InvalidArgumentException("The kind of platform category [{$definition['system_key']}] can not change.");
                }
            }

            $this->retire($existing, $wanted, $definitions);

            // Positions are unique: park every row that moves, then place it.
            $moving = [];
            foreach ($definitions as $definition) {
                $row = $existing->get($definition['system_key']);

                if ($row !== null && $row->position !== $definition['position']) {
                    $moving[] = $row->id;
                }
            }

            if ($moving !== []) {
                Category::query()->whereIn('id', $moving)->toBase()->update(['position' => DB::raw('-position')]);
            }

            foreach ($definitions as $definition) {
                $row = $existing->get($definition['system_key']);
                $columns = [
                    'names' => $definition['names'],
                    'icon' => $definition['icon'],
                    'color' => $definition['color'],
                    'position' => $definition['position'],
                    'archived_at' => null,
                ];

                if ($row === null) {
                    Category::query()->forceCreate([
                        'id' => (string) Str::uuid7(),
                        'system_key' => $definition['system_key'],
                        'kind' => $definition['kind'],
                        ...$columns,
                        'version' => 1,
                    ]);

                    continue;
                }

                $changed = $row->names != $definition['names']
                    || $row->icon !== $definition['icon']
                    || $row->color !== $definition['color']
                    || $row->position !== $definition['position']
                    || $row->archived_at !== null;

                if ($changed) {
                    $this->update($row->id, $columns);
                }
            }
        });
    }

    /**
     * Retires every platform category no longer defined, once; a retired row
     * leaves the positions the definitions need.
     *
     * @param  Collection<string, Category>  $existing
     * @param  array<string, array<string, mixed>>  $wanted
     * @param  list<array<string, mixed>>  $definitions
     */
    private function retire($existing, array $wanted, array $definitions): void
    {
        $taken = array_column($definitions, 'position');
        $next = max([0, ...$taken, ...$existing->pluck('position')->map(fn ($position): int => abs((int) $position))->all()]) + 1;

        foreach ($existing as $key => $row) {
            if (isset($wanted[$key])) {
                continue;
            }

            $columns = [];

            if ($row->archived_at === null) {
                $columns['archived_at'] = now()->format('Y-m-d H:i:s.uP');
            }

            if (in_array($row->position, $taken, true)) {
                $columns['position'] = $next++;
            }

            if ($columns !== []) {
                $this->update($row->id, $columns);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function update(string $id, array $columns): void
    {
        if (isset($columns['names'])) {
            $columns['names'] = json_encode($columns['names'], JSON_UNESCAPED_UNICODE);
        }

        Category::query()->whereKey($id)->toBase()->update([
            ...$columns,
            'version' => DB::raw('version + 1'),
            'updated_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
