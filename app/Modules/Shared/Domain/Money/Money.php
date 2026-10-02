<?php

namespace App\Modules\Shared\Domain\Money;

use InvalidArgumentException;
use OverflowException;

/**
 * An amount of money: a signed integer count of minor units and its
 * currency (P-03 rule 1, ADR-0003 rules 1 and 5). Immutable. There is no
 * division, no rounding and no allocation: the ledger only adds, subtracts
 * and negates whole minor units, and allocates a total over equal parts
 * (P-03 rule 4). There is no division and no rounding. Arithmetic across
 * currencies throws CurrencyMismatch.
 */
final readonly class Money
{
    /** The largest magnitude a client's number type holds exactly: 2^53 - 1. */
    public const MAX_MINOR = 9007199254740991;

    private function __construct(
        public int $amountMinor,
        public Currency $currency,
    ) {}

    public static function of(int $amountMinor, string $currencyCode): self
    {
        return self::from($amountMinor, Currency::of($currencyCode));
    }

    public static function from(int $amountMinor, Currency $currency): self
    {
        if (abs($amountMinor) > self::MAX_MINOR) {
            throw new OverflowException('An amount beyond ±'.self::MAX_MINOR.' minor units is not representable.');
        }

        return new self($amountMinor, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::from($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::from($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amountMinor, $this->currency);
    }

    /**
     * Splits the amount into $parts equal shares that sum exactly to it
     * (P-03 rule 4): every share is the whole-unit quotient and the
     * remainder goes one minor unit at a time to the first shares. A negative
     * amount splits symmetrically, its shares as negative as the positive's
     * are positive.
     *
     * @return list<self>
     *
     * @throws InvalidArgumentException when $parts is below 1
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('An amount is split into at least one part.');
        }

        $sign = $this->amountMinor < 0 ? -1 : 1;
        $magnitude = abs($this->amountMinor);
        $share = intdiv($magnitude, $parts);
        $remainder = $magnitude % $parts;

        $shares = [];
        for ($index = 0; $index < $parts; $index++) {
            $shares[] = new self($sign * ($share + ($index < $remainder ? 1 : 0)), $this->currency);
        }

        return $shares;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency->code === $other->currency->code;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency->code !== $other->currency->code) {
            throw new CurrencyMismatch($this->currency->code, $other->currency->code);
        }
    }
}
