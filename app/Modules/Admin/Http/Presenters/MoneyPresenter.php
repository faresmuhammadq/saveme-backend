<?php

namespace App\Modules\Admin\Http\Presenters;

/**
 * Formats money for the back office from integer minor units and an ISO 4217
 * code (spec 006, FR-020, FR-021; contracts/values-and-rtl.md).
 *
 * Every step is integer or string arithmetic. number_format(), NumberFormatter
 * and round() are deliberately absent: each goes through a float and loses
 * digits past 2^53 (constitution P-03). Digits are always Western (P-14).
 */
class MoneyPresenter
{
    private const MINUS = "\u{2212}";

    /**
     * Minor-unit exponent and Arabic symbol per currency. Unknown codes use
     * exponent 2 and show the ISO code.
     *
     * @var array<string, array{exponent: int, ar: string}>
     */
    private const CURRENCIES = [
        'EGP' => ['exponent' => 2, 'ar' => 'ج.م'],
        'SAR' => ['exponent' => 2, 'ar' => 'ر.س'],
        'AED' => ['exponent' => 2, 'ar' => 'د.إ'],
        'USD' => ['exponent' => 2, 'ar' => 'US$'],
    ];

    /**
     * Compact magnitudes, largest first: major-unit digits to drop, suffix per language.
     *
     * @var list<array{digits: int, en: string, ar: string}>
     */
    private const MAGNITUDES = [
        ['digits' => 9, 'en' => 'B', 'ar' => 'مليار'],
        ['digits' => 6, 'en' => 'M', 'ar' => 'مليون'],
        ['digits' => 3, 'en' => 'K', 'ar' => 'ألف'],
    ];

    public function format(int $minor, string $currency, string $locale): string
    {
        $currency = strtoupper($currency);
        [$negative, $major, $fraction] = $this->split($minor, $this->exponent($currency));

        if ($locale === 'ar') {
            $number = $this->group($major).(trim($fraction, '0') === '' ? '' : ".{$fraction}");

            return ($negative ? self::MINUS : '')."{$number} {$this->symbol($currency)}";
        }

        $number = $this->group($major).($fraction === '' ? '' : ".{$fraction}");

        return ($negative ? self::MINUS : '')."{$currency} {$number}";
    }

    /**
     * A shortened figure for aggregate tiles: one decimal, truncated.
     */
    public function compact(int $minor, string $currency, string $locale): string
    {
        $currency = strtoupper($currency);
        [$negative, $major] = $this->split($minor, $this->exponent($currency));

        $number = $this->group($major);
        $suffix = '';

        foreach (self::MAGNITUDES as $magnitude) {
            if (strlen($major) > $magnitude['digits']) {
                $whole = substr($major, 0, -$magnitude['digits']);
                $tenth = $major[strlen($whole)];
                $number = $this->group($whole).($tenth === '0' ? '' : ".{$tenth}");
                $suffix = $magnitude[$locale === 'ar' ? 'ar' : 'en'];
                break;
            }
        }

        $sign = $negative ? self::MINUS : '';

        if ($locale === 'ar') {
            return $sign.trim("{$number} {$suffix}").' '.$this->symbol($currency);
        }

        return "{$sign}{$currency} {$number}{$suffix}";
    }

    /**
     * The editable major-unit text for a money input: "1290.00", no grouping.
     */
    public function plain(int $minor, string $currency): string
    {
        [$negative, $major, $fraction] = $this->split($minor, $this->exponent(strtoupper($currency)));

        return ($negative ? '-' : '').$major.($fraction === '' ? '' : ".{$fraction}");
    }

    /**
     * The machine-readable value for a <data> element: "129000 EGP".
     */
    public function machineValue(int $minor, string $currency): string
    {
        return $minor.' '.strtoupper($currency);
    }

    /**
     * @return array{0: bool, 1: string, 2: string} negative, major digits, fraction digits
     */
    private function split(int $minor, int $exponent): array
    {
        $digits = ltrim((string) $minor, '-');
        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);

        $major = $exponent === 0 ? $digits : substr($digits, 0, -$exponent);
        $fraction = $exponent === 0 ? '' : substr($digits, -$exponent);

        return [$minor < 0, $major, $fraction];
    }

    private function group(string $digits): string
    {
        return strrev(implode(',', str_split(strrev($digits), 3)));
    }

    private function exponent(string $currency): int
    {
        return self::CURRENCIES[$currency]['exponent'] ?? 2;
    }

    private function symbol(string $currency): string
    {
        return self::CURRENCIES[$currency]['ar'] ?? $currency;
    }
}
