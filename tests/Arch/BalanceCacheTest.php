<?php

/*
|--------------------------------------------------------------------------
| P-05 rule 3: nothing reads the balance cache
|--------------------------------------------------------------------------
|
| No policy, invariant or report reads `balance_minor`: it is a cache of
| opening balance + sum of postings, never an input (ADR-0005). The only
| code that may name it is Wallets' persistence, the wallet record, the
| query service that hands the record to a read, and the wallet resource.
| The authorizer and the write service decide from the ledger, never from it.
|
*/

test('P-05: balance_minor is named only where the cache is stored or presented', function () {
    $allowed = [
        'app/Modules/Wallets/Infrastructure/',
        'app/Modules/Wallets/Domain/Wallets/WalletRecord.php',
        'app/Modules/Wallets/Application/WalletQueryService.php',
        'app/Modules/Wallets/Http/Resources/WalletResource.php',
    ];
    $root = dirname(__DIR__, 2);
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace($root.'/', '', $file->getPathname());

        foreach ($allowed as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                continue 2;
            }
        }

        // `opening_balance_minor` is not the cache: the pattern skips a longer name.
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/(?<![a-z_])balance_minor|(?<![A-Za-z])balanceMinor/', $source) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'P-05 rule 3: the balance cache is read outside Wallets persistence and the wallet resource: '.implode(', ', $offenders));
});
