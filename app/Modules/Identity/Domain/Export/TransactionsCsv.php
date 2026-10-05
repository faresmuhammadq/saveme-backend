<?php

namespace App\Modules\Identity\Domain\Export;

use RuntimeException;

/**
 * The transactions spreadsheet of an export (spec 022, FR-005, R-2): UTF-8
 * with a byte-order mark so Arabic opens correctly, comma-separated,
 * RFC 4180 quoting, CRLF line ends. Amounts are integer minor units beside
 * their currency (P-03). A text cell that begins like a formula is prefixed
 * with an apostrophe so a spreadsheet does not evaluate it; numbers never
 * are.
 */
final class TransactionsCsv
{
    public const BOM = "\xEF\xBB\xBF";

    public const COLUMNS = [
        'date', 'wallet', 'wallet_id', 'kind', 'amount_minor', 'currency', 'category', 'counter_wallet',
        'description', 'memo', 'counterparty', 'cleared', 'author', 'transaction_id', 'created_at', 'updated_at',
    ];

    public static function headerLine(): string
    {
        return self::BOM.self::encode(self::COLUMNS);
    }

    /**
     * @param  array<string, string|int|bool|null>  $row  keyed by column; a missing column is empty
     */
    public static function line(array $row): string
    {
        $cells = [];

        foreach (self::COLUMNS as $column) {
            $cells[] = self::cell($row[$column] ?? null);
        }

        return self::encode($cells);
    }

    /**
     * A text cell safe from formula evaluation; integers and booleans pass.
     */
    public static function cell(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  list<string>  $cells
     */
    private static function encode(array $cells): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('No memory stream for the spreadsheet.');
        }

        fputcsv($stream, $cells, ',', '"', '', "\r\n");
        rewind($stream);
        $line = (string) stream_get_contents($stream);
        fclose($stream);

        return $line;
    }
}
