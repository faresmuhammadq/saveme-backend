<?php

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Application\Dto\ExcludedWallet;
use App\Modules\Shared\Domain\Money\Money;

/**
 * Money on the wire: `{amount_minor, currency}`.
 */
final class ReportMoney
{
    /**
     * @return array{amount_minor: int, currency: string}
     */
    public static function of(Money $money): array
    {
        return ['amount_minor' => $money->amountMinor, 'currency' => $money->currency->code];
    }

    /**
     * @param  list<Money>  $series
     * @param  list<string>  $labels
     * @return list<array{label: string, value: array{amount_minor: int, currency: string}}>
     */
    public static function series(array $series, array $labels): array
    {
        return array_map(
            fn (Money $money, string $label): array => ['label' => $label, 'value' => self::of($money)],
            $series,
            $labels,
        );
    }

    /**
     * The `meta` of a report: empty for a wallet report, the excluded wallets across wallets.
     *
     * @param  list<ExcludedWallet>|null  $excluded
     * @return array<string, mixed>
     */
    public static function meta(?array $excluded): array
    {
        if ($excluded === null) {
            return [];
        }

        return ['excluded_wallets' => array_map(fn ($wallet): array => ['wallet_id' => $wallet->walletId, 'currency' => $wallet->currency], $excluded)];
    }
}
