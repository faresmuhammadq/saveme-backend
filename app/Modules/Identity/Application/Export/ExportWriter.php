<?php

namespace App\Modules\Identity\Application\Export;

/**
 * Where an ExportSection puts what it reads: JSON files holding one array
 * each, and the transactions spreadsheet. Streams to temporary files, so
 * memory stays flat however large the account (spec 022, R-4).
 */
interface ExportWriter
{
    /**
     * Makes sure the file exists in the archive, empty if nothing is appended.
     */
    public function declare(string $file): void;

    /**
     * Adds one record to the JSON array of $file (for example `wallets.json`).
     *
     * @param  array<string, mixed>  $record
     */
    public function append(string $file, array $record): void;

    /**
     * Adds one row to `transactions.csv`, keyed by the header's columns.
     *
     * @param  array<string, string|int|bool|null>  $row
     */
    public function transactionRow(array $row): void;
}
