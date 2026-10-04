<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;
use LogicException;

/**
 * The summary arithmetic of a Report (spec 019, FR-009, FR-010, research
 * R-2 and R-4). Pure: wallet-leg sums in, Money out, `plus`, `minus` and
 * `negate` only. Income and expenses come from the signs of the legs; a
 * transfer is `transfers_in` or `transfers_out`, never income or expense,
 * and one between two covered wallets is neither (it nets to zero in the
 * balances). `balance_end = balance_start + income - expenses + transfers_in
 * - transfers_out` always holds.
 */
final readonly class SummaryFigures
{
    public Money $net;

    public Money $balanceEnd;

    private function __construct(
        public Money $income,
        public Money $expenses,
        public Money $transfersIn,
        public Money $transfersOut,
        public Money $balanceStart,
        Money $legSum,
    ) {
        $this->net = $income->minus($expenses);
        $this->balanceEnd = $balanceStart->plus($legSum);

        if (! $balanceStart->plus($this->net)->plus($transfersIn)->minus($transfersOut)->equals($this->balanceEnd)) {
            throw new LogicException('The balance identity does not hold for the report legs.');
        }
    }

    /**
     * @param  list<LegTotal>  $legs  every covered wallet's legs in the range
     * @param  Money  $balanceStart  the covered wallets' opening balances plus their legs dated before the range
     * @param  list<string>  $coveredWalletIds  the wallets reported on
     */
    public static function from(array $legs, Money $balanceStart, array $coveredWalletIds): self
    {
        $zero = Money::from(0, $balanceStart->currency);
        $covered = array_flip($coveredWalletIds);
        $income = $expenses = $in = $out = $sum = $zero;

        foreach ($legs as $leg) {
            $sum = $sum->plus($leg->amount);

            if ($leg->kind === LegTotal::INCOME) {
                $income = $income->plus($leg->amount);
            } elseif ($leg->kind === LegTotal::EXPENSE) {
                $expenses = $expenses->minus($leg->amount);
            } elseif ($leg->otherWalletId === null || ! isset($covered[$leg->otherWalletId])) {
                if ($leg->amount->amountMinor > 0) {
                    $in = $in->plus($leg->amount);
                } else {
                    $out = $out->minus($leg->amount);
                }
            }
        }

        return new self($income, $expenses, $in, $out, $balanceStart, $sum);
    }

    /**
     * Nothing covered: every figure zero in the currency.
     */
    public static function empty(string $currency): self
    {
        return self::from([], Money::of(0, $currency), []);
    }
}
