<?php

namespace App\Modules\Billing\Infrastructure\Database\Seeders;

use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\PlanCode;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Shared\Domain\Money\Currency;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * The launch catalogue (spec 009, owner decision Q2 = B), as reference data:
 * idempotent, keyed on code, safe in production. Every plan must declare
 * every capability, so an omission fails here rather than silently denying.
 */
class BillingSeeder extends Seeder
{
    /**
     * @return list<array{code: string, name: array{ar: string, en: string}, period_type: string, period_days: ?int, is_default: bool, status: string, sort_order: int, features: array<string, bool|int|null>, prices: list<array{channel: string, product_id: ?string, amount_minor: int, currency: string}>}>
     */
    public static function definitions(): array
    {
        $everythingButSharing = [
            'export.enabled' => true,
            'reports.advanced' => true,
            'wallets.max' => null,
        ];

        return [
            [
                'code' => 'free', 'name' => ['en' => 'Free', 'ar' => 'مجاني'],
                'period_type' => 'lifetime', 'period_days' => null, 'is_default' => true, 'status' => 'active', 'sort_order' => 1,
                'features' => ['sharing.enabled' => false, 'sharing.max_members_per_wallet' => 1, ...$everythingButSharing],
                'prices' => [],
            ],
            [
                'code' => 'plus_monthly', 'name' => ['en' => 'Plus · Monthly', 'ar' => 'بلس · شهري'],
                'period_type' => 'monthly', 'period_days' => null, 'is_default' => false, 'status' => 'active', 'sort_order' => 2,
                'features' => ['sharing.enabled' => true, 'sharing.max_members_per_wallet' => 5, ...$everythingButSharing],
                'prices' => [['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 14900, 'currency' => 'EGP']],
            ],
            [
                'code' => 'plus_yearly', 'name' => ['en' => 'Plus · Yearly', 'ar' => 'بلس · سنوي'],
                'period_type' => 'yearly', 'period_days' => null, 'is_default' => false, 'status' => 'active', 'sort_order' => 3,
                'features' => ['sharing.enabled' => true, 'sharing.max_members_per_wallet' => 5, ...$everythingButSharing],
                'prices' => [['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 129000, 'currency' => 'EGP']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function validate(array $definition): void
    {
        $code = (new PlanCode((string) ($definition['code'] ?? '')))->value;

        foreach (Feature::cases() as $feature) {
            if (! array_key_exists($feature->value, (array) ($definition['features'] ?? []))) {
                throw new InvalidArgumentException("Plan [{$code}] does not declare capability [{$feature->value}].");
            }
        }

        foreach ((array) ($definition['prices'] ?? []) as $price) {
            if (! is_array($price) || ! is_int($price['amount_minor'] ?? null) || $price['amount_minor'] < 0 || ! Currency::isKnown((string) ($price['currency'] ?? ''))) {
                throw new InvalidArgumentException("Plan [{$code}] has a price that is not integer minor units in a known currency.");
            }
        }
    }

    public function run(): void
    {
        $definitions = self::definitions();
        array_walk($definitions, fn (array $definition) => self::validate($definition));

        foreach ($definitions as $definition) {
            $plan = Plan::query()->firstOrNew(['code' => $definition['code']]);

            // jsonb reorders object keys, so compare values, not key order.
            $changed = array_filter($definition, fn (mixed $value, string $column): bool => $plan->getAttribute($column) != $value, ARRAY_FILTER_USE_BOTH);

            if (! $plan->exists || $changed !== []) {
                $plan->fill($definition)->save();
            }
        }
    }
}
