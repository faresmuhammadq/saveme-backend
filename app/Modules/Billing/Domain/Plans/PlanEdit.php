<?php

namespace App\Modules\Billing\Domain\Plans;

use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\NothingToSave;
use App\Modules\Billing\Domain\Refusals\PlanEditInvalid;
use App\Modules\Shared\Domain\Money\Currency;

/**
 * An edit of a stored plan, checked (spec 021, FR-032 to FR-035; data-model.md,
 * Plan). Only the name, display order, InstaPay price and capability values
 * can change; the period, default flag, code, status and Google Play prices
 * are never touched. Pure rules, no persistence.
 */
final readonly class PlanEdit
{
    /**
     * @param  list<string>  $fields  changed field names, sorted
     * @param  array{name: array{ar: string, en: string}, sort_order: int, features: array<string, bool|int|null>, prices: list<array<string, mixed>>}  $columns  the values to store
     */
    private function __construct(public array $fields, public array $columns) {}

    /**
     * @throws PlanEditInvalid carrying the field keys at fault
     * @throws NothingToSave when no field differs from the stored plan
     */
    public static function of(PlanRecord $stored, PlanChangeSet $changes): self
    {
        $invalid = [];
        $changed = [];

        $name = [];
        foreach (['en', 'ar'] as $language) {
            $value = is_string($changes->name[$language]) ? trim($changes->name[$language]) : null;

            if ($value === null || mb_strlen($value) < 1 || mb_strlen($value) > 40) {
                $invalid[] = "name.{$language}";
                $name[$language] = $stored->name[$language];

                continue;
            }

            $name[$language] = $value;
            if ($value !== $stored->name[$language]) {
                $changed[] = "name.{$language}";
            }
        }

        $sortOrder = $changes->sortOrder;
        if (! is_int($sortOrder) || $sortOrder < 1 || $sortOrder > 999) {
            $invalid[] = 'sort_order';
            $sortOrder = $stored->sortOrder;
        } elseif ($sortOrder !== $stored->sortOrder) {
            $changed[] = 'sort_order';
        }

        $prices = self::prices($stored, $changes->instapayPrice, $invalid, $changed);
        $features = self::features($stored, $changes->features, $invalid, $changed);

        if ($invalid !== []) {
            throw new PlanEditInvalid(self::sorted($invalid));
        }

        if ($changed === []) {
            throw new NothingToSave;
        }

        return new self(self::sorted($changed), [
            'name' => ['en' => $name['en'], 'ar' => $name['ar']],
            'sort_order' => $sortOrder,
            'features' => $features,
            'prices' => $prices,
        ]);
    }

    /**
     * @param  array{amount_minor: mixed, currency: mixed}|null  $draft
     * @param  list<string>  $invalid
     * @param  list<string>  $changed
     * @return list<array<string, mixed>>
     */
    private static function prices(PlanRecord $stored, ?array $draft, array &$invalid, array &$changed): array
    {
        $current = null;
        foreach ($stored->prices as $price) {
            if ($price['channel'] === 'instapay') {
                $current = $price;
                break;
            }
        }

        if ($draft === null) {
            if ($current !== null) {
                $changed[] = 'prices.instapay';
            }

            return array_values(array_filter($stored->prices, fn (array $price): bool => $price['channel'] !== 'instapay'));
        }

        $amount = $draft['amount_minor'];
        $currency = $draft['currency'];

        if (! is_int($amount) || $amount < 100 || $amount > 10_000_000 || ! is_string($currency) || ! Currency::isKnown($currency)) {
            $invalid[] = 'prices.instapay';

            return $stored->prices;
        }

        if ($current === null || $current['amount_minor'] !== $amount || $current['currency'] !== $currency) {
            $changed[] = 'prices.instapay';
        }

        $entry = ['channel' => 'instapay', 'product_id' => $current['product_id'] ?? null, 'amount_minor' => $amount, 'currency' => $currency];

        if ($current === null) {
            return [...$stored->prices, $entry];
        }

        return array_map(fn (array $price): array => $price['channel'] === 'instapay' ? $entry : $price, $stored->prices);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<string>  $invalid
     * @param  list<string>  $changed
     * @return array<string, bool|int|null>
     */
    private static function features(PlanRecord $stored, array $draft, array &$invalid, array &$changed): array
    {
        $features = $stored->features;

        foreach (array_keys($draft) as $key) {
            if (Feature::tryFrom((string) $key) === null) {
                $invalid[] = "features.{$key}";
            }
        }

        foreach (Feature::cases() as $feature) {
            if (! array_key_exists($feature->value, $draft)) {
                continue;
            }

            $value = $draft[$feature->value];
            $fixed = $stored->isDefault && ! in_array($feature, [Feature::SharingEnabled, Feature::SharingMaxMembersPerWallet], true);

            $valid = $feature->isQuota()
                ? $value === null || (is_int($value) && $value >= 1 && $value <= 1000)
                : is_bool($value);

            if ($fixed) {
                $valid = $feature->isQuota() ? $value === null : $value === true;
            }

            if (! $valid) {
                $invalid[] = "features.{$feature->value}";

                continue;
            }

            if (($stored->features[$feature->value] ?? null) !== $value) {
                $changed[] = "features.{$feature->value}";
            }

            $features[$feature->value] = $value;
        }

        return $features;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }
}
