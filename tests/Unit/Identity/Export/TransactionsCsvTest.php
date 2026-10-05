<?php

use App\Modules\Identity\Domain\Export\TransactionsCsv;

/*
 * The transactions spreadsheet of an export (spec 022, FR-005, R-2): no
 * framework, no database.
 */

const CSV_HEADER = 'date,wallet,wallet_id,kind,amount_minor,currency,category,counter_wallet,description,memo,counterparty,cleared,author,transaction_id,created_at,updated_at';

test('the header starts with a byte-order mark and names the sixteen columns exactly, CRLF-terminated', function () {
    $header = TransactionsCsv::headerLine();

    expect(str_starts_with($header, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(substr($header, 3))->toBe(CSV_HEADER."\r\n")
        ->and(TransactionsCsv::COLUMNS)->toHaveCount(16);
});

test('commas, quotes and line breaks are quoted the RFC 4180 way', function () {
    $line = TransactionsCsv::line(['description' => 'a, "b"', 'memo' => "two\nlines"]);

    expect($line)->toContain('"a, ""b"""')
        ->and($line)->toContain("\"two\nlines\"")
        ->and(str_ends_with($line, "\r\n"))->toBeTrue();
});

test('Arabic text round-trips through the file encoding', function () {
    $line = TransactionsCsv::line(['wallet' => 'محفظة النقد', 'description' => 'غداء']);
    $parsed = str_getcsv(rtrim($line, "\r\n"), ',', '"', '');

    expect($parsed[1])->toBe('محفظة النقد')->and($parsed[8])->toBe('غداء')
        ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
});

test('a text cell that begins like a formula is prefixed with an apostrophe', function (string $text) {
    expect(TransactionsCsv::cell($text))->toBe("'".$text);
})->with(['=SUM(A1)', '+1', '-2+3', '@cmd', "\tTab", "\rCR"]);

test('ordinary text, empty text and numbers are left alone', function () {
    expect(TransactionsCsv::cell('Lunch'))->toBe('Lunch')
        ->and(TransactionsCsv::cell(''))->toBe('')
        ->and(TransactionsCsv::cell(null))->toBe('')
        ->and(TransactionsCsv::cell(-2500))->toBe('-2500')
        ->and(TransactionsCsv::cell(true))->toBe('true')
        ->and(TransactionsCsv::cell(false))->toBe('false');
});

test('amounts are integer minor units, never prefixed even when negative', function () {
    $line = TransactionsCsv::line(['amount_minor' => -12345, 'currency' => 'EGP', 'description' => '=1+1']);
    $cells = str_getcsv(rtrim($line, "\r\n"), ',', '"', '');

    expect($cells[4])->toBe('-12345')->and($cells[5])->toBe('EGP')->and($cells[8])->toBe("'=1+1");
});
