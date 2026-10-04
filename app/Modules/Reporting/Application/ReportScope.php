<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Application\Dto\ExcludedWallet;
use App\Modules\Shared\Domain\Money\Money;
use App\Modules\Wallets\Application\Reports\Dto\ReportWallet;

/**
 * Which wallets a report covers (glossary: Report Range, covered and excluded
 * wallets). A wallet report covers its one wallet. A cross-wallet report
 * covers the caller's live wallets in the report's currency and lists the
 * rest, sorted by id, as excluded.
 */
final readonly class ReportScope
{
    /**
     * @param  list<ReportWallet>  $covered
     * @param  list<ExcludedWallet>|null  $excluded  null for a wallet report
     */
    private function __construct(
        public ?string $walletId,
        public string $currency,
        public array $covered,
        public ?array $excluded,
    ) {}

    public static function ofWallet(ReportWallet $wallet): self
    {
        return new self($wallet->id, $wallet->currency, [$wallet], null);
    }

    /**
     * @param  list<ReportWallet>  $wallets  the caller's live wallets, any currency
     */
    public static function across(array $wallets, string $currency): self
    {
        usort($wallets, fn (ReportWallet $a, ReportWallet $b): int => strcmp($a->id, $b->id));

        $covered = array_values(array_filter($wallets, fn (ReportWallet $wallet): bool => $wallet->currency === $currency));
        $excluded = array_map(
            fn (ReportWallet $wallet): ExcludedWallet => new ExcludedWallet($wallet->id, $wallet->currency),
            array_values(array_filter($wallets, fn (ReportWallet $wallet): bool => $wallet->currency !== $currency)),
        );

        return new self(null, $currency, $covered, $excluded);
    }

    /**
     * @return list<string>
     */
    public function coveredIds(): array
    {
        return array_map(fn (ReportWallet $wallet): string => $wallet->id, $this->covered);
    }

    /**
     * The undated opening balances of the covered wallets, which every range counts.
     */
    public function openingTotal(): Money
    {
        $total = Money::of(0, $this->currency);

        foreach ($this->covered as $wallet) {
            $total = $total->plus(Money::of($wallet->openingBalanceMinor, $this->currency));
        }

        return $total;
    }
}
