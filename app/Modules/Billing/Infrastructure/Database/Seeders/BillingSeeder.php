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
 * create-missing-only, keyed on code, safe in production: a deployment never undoes a back-office edit (spec 021). Every plan must declare
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

    /**
     * Create-missing-only (spec 021, FR-039, R-6): a plan whose code is absent
     * is inserted; a stored plan only gains the capability keys it lacks, with
     * the seeded value. Nothing an operator edited is ever overwritten.
     */
    public function run(): void
    {
        $definitions = self::definitions();
        array_walk($definitions, fn (array $definition) => self::validate($definition));

        foreach ($definitions as $definition) {
            $plan = Plan::query()->where('code', $definition['code'])->first();

            if ($plan === null) {
                Plan::query()->create($definition);

                continue;
            }

            $missing = array_diff_key($definition['features'], $plan->features);

            if ($missing !== []) {
                $plan->features = [...$plan->features, ...$missing];
                $plan->save();
            }
        }

        self::seedPlayPrices();
    }

    /**
     * Google Play product ids from configuration (spec 024, FR-040, FR-041, R-9):
     * a plan gains its `google_play` price only when it has none and the
     * configuration gives a product id, an integer amount in minor units and a
     * known currency. Never overwritten, never removed; a product id on two plans throws.
     */
    public static function seedPlayPrices(): void
    {
        $configured = [];

        foreach ((array) config('billing.google_play.products') as $code => $product) {
            $productId = is_array($product) ? ($product['product_id'] ?? null) : null;

            if (is_string($productId) && $productId !== '') {
                if (in_array($productId, $configured, true)) {
                    throw new InvalidArgumentException("Google Play product [{$productId}] is configured for two plans.");
                }

                $configured[] = $productId;
            }
        }

        foreach ((array) config('billing.google_play.products') as $code => $product) {
            $productId = $product['product_id'] ?? null;
            $amount = $product['amount_minor'] ?? null;
            $currency = $product['currency'] ?? null;

            if (! is_string($productId) || $productId === '' || ! is_int($amount) || $amount < 0 || ! is_string($currency) || ! Currency::isKnown($currency)) {
                continue;
            }

            $plan = Plan::query()->where('code', (string) $code)->first();

            if ($plan === null || collect($plan->prices)->contains(fn (array $price): bool => $price['channel'] === 'google_play')) {
                continue;
            }

            $taken = Plan::query()->where('id', '!=', $plan->id)->get()
                ->contains(fn (Plan $other): bool => collect($other->prices)->contains(fn (array $price): bool => $price['channel'] === 'google_play' && $price['product_id'] === $productId));

            if ($taken) {
                throw new InvalidArgumentException("Google Play product [{$productId}] already maps to another plan.");
            }

            $plan->prices = [...$plan->prices, ['channel' => 'google_play', 'product_id' => $productId, 'amount_minor' => $amount, 'currency' => $currency]];
            $plan->save();
        }
    }
}
