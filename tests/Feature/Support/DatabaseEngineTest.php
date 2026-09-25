<?php

use Illuminate\Support\Facades\DB;

/*
 * P-16 rule 2 and ADR-0001: the suite runs on the production engine,
 * PostgreSQL 16, never a substitute. Asserted here so a local run on the
 * wrong engine fails too, not only the pipeline.
 */
test('the suite runs on PostgreSQL 16', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $version = DB::selectOne('select version() as version')->version;

    expect($version)->toStartWith('PostgreSQL 16.');
});
